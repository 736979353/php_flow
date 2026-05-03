<?php

namespace app\admin\controller\tradeflow;

use app\admin\model\Admin;
use app\admin\library\tradeflow\Service as TradeflowService;
use app\common\controller\Backend;
use think\Db;
use think\Session;

class Instance extends Backend
{
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\tradeflow\Instance;
        $this->view->assign('statusList', $this->model->getStatusList());
        $this->ensureInstanceCcIdsField();
    }

    public function index()
    {
        if ($this->request->isAjax()) {
            list($where, $sort, $order, $offset, $limit) = $this->buildparams();
            $uid = $this->adminId();
            $scope = $this->request->get('scope', 'all');
            $query = $this->model->where($where);
            if ($scope === 'mine') {
                $query->where('initiator_id', $uid);
            } elseif ($scope === 'todo') {
                $query->where('status', 0)->where("FIND_IN_SET(" . intval($uid) . ", current_auditor_ids)");
            } elseif ($scope === 'copy') {
                $ids = \app\admin\model\tradeflow\Copy::where('receiver_id', $uid)->column('instance_id');
                $query->where('id', 'in', $ids ?: [0]);
            }
            $total = $query->count();
            $list = $query->order($sort, $order)->limit($offset, $limit)->select();
            return json(['total' => $total, 'rows' => $list]);
        }
        $this->view->assign('flowList', \app\admin\model\tradeflow\Flow::where('status', 1)->order('id desc')->select());
        return $this->view->fetch();
    }

    public function launch($ids = null)
    {
        $flow = \app\admin\model\tradeflow\Flow::get($ids ?: input('flow_id'));
        if (!$flow || (int)$flow['status'] !== 1) {
            $this->error('流程不存在或未启用');
        }
        $exists = \app\admin\model\tradeflow\Instance::where('flow_id', $flow['id'])->order('id desc')->find();
        if ($exists) {
            $this->error('该流程设计已发起过流程办理，不能重复发起');
        }
        $graph = json_decode($flow['graph'], true);
        if (!$graph) {
            $this->error('流程尚未设计');
        }
        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            $formData = $this->request->post('form/a', []);
            $ccIds = $this->requestCcIds();
            try {
                TradeflowService::launch($flow['id'], [
                    'title' => isset($params['title']) ? $params['title'] : '',
                    'initiator_id' => $this->adminId(),
                    'form_data' => $formData,
                    'cc_ids' => $ccIds,
                ]);
            } catch (\Exception $e) {
                $this->error($e->getMessage());
            }
            $this->success('流程已发起');
        }
        $start = $this->nodeById($graph, 'start');
        $defaultCcIds = $this->requestCcIds();
        $this->view->assign([
            'flow' => $flow,
            'fields' => $this->prepareFields(isset($start['form_fields']) ? $start['form_fields'] : []),
            'prefix' => 'form',
            'admins' => $this->adminOptions(),
            'defaultCcIds' => implode(',', $defaultCcIds),
        ]);
        return $this->view->fetch();
    }

    public function approve($ids = null)
    {
        $row = $this->model->get($ids);
        if (!$row || !in_array((int)$row['status'], [0, 1])) {
            $this->error('流程不存在或无需办理');
        }
        $uid = $this->adminId();
        $graph = json_decode($row['graph_snapshot'], true) ?: ['nodes' => []];
        $activeNodes = $this->activeNodes($row);
        $assignedNodes = $this->assignedNodes($activeNodes, $uid);
        $completedNodes = $this->completedAssignedNodes($row, $graph, $uid);
        $nodeOptions = $this->mergeNodes($assignedNodes, $completedNodes);
        $activeOptionIds = array_map(function ($item) {
            return isset($item['id']) ? $item['id'] : '';
        }, $assignedNodes);
        foreach ($nodeOptions as &$optionNode) {
            $optionNode['edit_status'] = in_array($optionNode['id'], $activeOptionIds) ? 'active' : 'completed';
        }
        unset($optionNode);
        if (!$nodeOptions) {
            $this->error('当前节点未分配给你办理');
        }
        $nodeId = $this->request->request('node_id', '');
        $node = $nodeId ? $this->nodeById(['nodes' => $nodeOptions], $nodeId) : $nodeOptions[0];
        if (!$node) {
            $this->error('当前节点不存在');
        }
        if (!$this->nodeById(['nodes' => $nodeOptions], $node['id'])) {
            $this->error('当前节点未分配给你办理');
        }
        $isActiveNode = $this->nodeById(['nodes' => $assignedNodes], $node['id']) ? true : false;
        if ($this->request->isPost()) {
            $action = $this->request->post('action', 'approve');
            $comment = $this->request->post('comment', '');
            $formData = $this->request->post('form/a', []);
            $message = '节点已处理';
            Db::startTrans();
            try {
                $stored = json_decode($row['form_data'], true) ?: [];
                $stored[$node['id']] = $formData;
                if (!$isActiveNode) {
                    $this->writeLog($row['id'], $node['id'], $node['label'], $node['type'], $uid, 'update', $comment ?: '修改/补充节点表单', $formData);
                    $this->saveInstance($row, ['form_data' => json_encode($stored, JSON_UNESCAPED_UNICODE)]);
                    $message = '节点信息已更新';
                } else {
                    $this->writeLog($row['id'], $node['id'], $node['label'], $node['type'], $uid, $action, $comment, $formData);
                    if ($action === 'reject') {
                        $this->saveInstance($row, ['status' => 2, 'current_auditor_ids' => '', 'current_node_name' => '已拒绝', 'active_nodes_data' => '[]']);
                    } else {
                        $completed = $this->completedNodeIds($row);
                        $completed[] = $node['id'];
                        $completed = array_values(array_unique($completed));
                        $remaining = [];
                        foreach ($activeNodes as $activeNode) {
                            if ($activeNode['id'] !== $node['id']) {
                                $remaining[] = $activeNode;
                            }
                        }
                        if ($remaining) {
                            $summary = $this->activeSummary($remaining);
                            $this->saveInstance($row, [
                                'current_node_id' => $summary['ids'],
                                'current_node_name' => $summary['names'],
                                'current_auditor_ids' => $summary['auditor_ids'],
                                'active_nodes_data' => json_encode($remaining, JSON_UNESCAPED_UNICODE),
                                'completed_node_ids' => implode(',', $completed),
                                'form_data' => json_encode($stored, JSON_UNESCAPED_UNICODE),
                            ]);
                        } else {
                            $currentStageIndex = $this->stageIndexOf($graph, $node['id']);
                            $nextStage = $this->stageAfter($graph, $currentStageIndex);
                            if (!$nextStage || $this->isEndStage($nextStage)) {
                                $this->saveInstance($row, [
                                    'status' => 1,
                                    'current_node_id' => 'end',
                                    'current_node_name' => '结束',
                                    'current_auditor_ids' => '',
                                    'active_nodes_data' => '[]',
                                    'completed_node_ids' => implode(',', array_values(array_unique(array_merge($completed, ['end'])))),
                                    'form_data' => json_encode($stored, JSON_UNESCAPED_UNICODE),
                                ]);
                                $this->writeLog($row['id'], 'end', '结束', 'end', $uid, 'finish', '流程完成', []);
                            } else {
                                $summary = $this->activeSummary($nextStage);
                                $this->saveInstance($row, [
                                    'current_node_id' => $summary['ids'],
                                    'current_node_name' => $summary['names'],
                                    'current_auditor_ids' => $summary['auditor_ids'],
                                    'active_nodes_data' => json_encode($nextStage, JSON_UNESCAPED_UNICODE),
                                    'completed_node_ids' => implode(',', $completed),
                                    'form_data' => json_encode($stored, JSON_UNESCAPED_UNICODE),
                                ]);
                                foreach ($nextStage as $nextNode) {
                                    $this->writeCopies($row['id'], $nextNode, $this->instanceCcIds($row));
                                }
                            }
                        }
                    }
                }
                Db::commit();
            } catch (\Exception $e) {
                Db::rollback();
                $this->error($e->getMessage());
            }
            $this->success($message);
        }
        $this->view->assign([
            'row' => $row,
            'node' => $node,
            'activeNodeOptions' => $nodeOptions,
            'isActiveNode' => $isActiveNode,
            'fields' => $this->fieldsWithValues($this->prepareFields(isset($node['form_fields']) ? $node['form_fields'] : []), $this->storedNodeData($row, $node['id'])),
            'prefix' => 'form',
            'storedData' => $this->storedNodeData($row, $node['id'])
        ]);
        return $this->view->fetch();
    }

    public function detail($ids = null)
    {
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        $uid = $this->adminId();
        \app\admin\model\tradeflow\Copy::where(['instance_id' => $row['id'], 'receiver_id' => $uid])->update(['is_read' => 1]);
        $logs = \app\admin\model\tradeflow\Log::where('instance_id', $row['id'])->order('id asc')->select();
        $graph = json_decode($row['graph_snapshot'], true) ?: ['nodes' => []];
        $readableLogs = [];
        $logsByNode = [];
        foreach ($logs as $log) {
            $item = $log->toArray();
            $node = $this->nodeById($graph, $item['node_id']);
            $fields = $node && isset($node['form_fields']) ? $this->prepareFields($node['form_fields']) : [];
            $item['action_text'] = $this->actionText($item['action']);
            $item['form_items'] = $this->readableFormItems($fields, json_decode($item['form_data'], true) ?: []);
            $readableLogs[] = $item;
            if (!isset($logsByNode[$item['node_id']])) {
                $logsByNode[$item['node_id']] = [];
            }
            $logsByNode[$item['node_id']][] = $item;
        }
        $nodeDetails = $this->detailNodes($row, $graph, $logsByNode);
        $this->view->assign([
            'row' => $row,
            'logs' => $readableLogs,
            'nodeDetails' => $nodeDetails,
            'formData' => json_decode($row['form_data'], true) ?: [],
        ]);
        return $this->view->fetch();
    }

    protected function nextNode($graph, $from)
    {
        foreach ($graph['edges'] as $edge) {
            if ($edge['from'] === $from) {
                return $this->nodeById($graph, $edge['to']);
            }
        }
        return null;
    }

    protected function stages($graph)
    {
        $start = $this->nodeById($graph, 'start');
        $end = $this->nodeById($graph, 'end');
        $middle = [];
        foreach (isset($graph['nodes']) ? $graph['nodes'] : [] as $node) {
            if ($node['type'] !== 'start' && $node['type'] !== 'end') {
                $middle[] = $node;
            }
        }
        usort($middle, function ($a, $b) {
            if ($a['x'] == $b['x']) {
                return $a['y'] <=> $b['y'];
            }
            return $a['x'] <=> $b['x'];
        });
        $stages = [];
        foreach ($middle as $node) {
            $lastIndex = count($stages) - 1;
            if ($lastIndex >= 0 && abs($stages[$lastIndex][0]['x'] - $node['x']) <= 120) {
                $stages[$lastIndex][] = $node;
            } else {
                $stages[] = [$node];
            }
        }
        foreach ($stages as &$stage) {
            usort($stage, function ($a, $b) {
                return $a['y'] <=> $b['y'];
            });
        }
        if ($start) {
            array_unshift($stages, [$start]);
        }
        if ($end) {
            $stages[] = [$end];
        }
        return $stages;
    }

    protected function stageAfter($graph, $stageIndex)
    {
        $stages = $this->stages($graph);
        return isset($stages[$stageIndex + 1]) ? $stages[$stageIndex + 1] : [];
    }

    protected function stageIndexOf($graph, $nodeId)
    {
        foreach ($this->stages($graph) as $index => $stage) {
            foreach ($stage as $node) {
                if ($node['id'] === $nodeId) {
                    return $index;
                }
            }
        }
        return 0;
    }

    protected function isEndStage($stage)
    {
        return count($stage) === 1 && isset($stage[0]['type']) && $stage[0]['type'] === 'end';
    }

    protected function activeNodes($row)
    {
        $nodes = json_decode($this->modelValue($row, 'active_nodes_data', ''), true);
        if (is_array($nodes) && $nodes) {
            return $nodes;
        }
        $graph = json_decode($row['graph_snapshot'], true) ?: ['nodes' => []];
        $currentNodeIds = array_filter(explode(',', (string)$row['current_node_id']));
        $fallbackNodes = [];
        foreach ($currentNodeIds as $currentNodeId) {
            $fallback = $this->nodeById($graph, $currentNodeId);
            if ($fallback) {
                $fallbackNodes[] = $fallback;
            }
        }
        if ($fallbackNodes) {
            return $fallbackNodes;
        }
        $fallback = $this->nodeById($graph, (string)$row['current_node_id']);
        return $fallback ? [$fallback] : [];
    }

    protected function assignedNodes($nodes, $uid)
    {
        $assigned = [];
        foreach ($nodes as $node) {
            $auditors = $this->nodeAuditors($node);
            if (!$auditors || in_array((string)$uid, array_map('strval', $auditors))) {
                $assigned[] = $node;
            }
        }
        return $assigned;
    }

    protected function completedAssignedNodes($row, $graph, $uid)
    {
        $completedIds = $this->historicalNodeIds($row, $uid);
        $nodes = [];
        foreach ($completedIds as $nodeId) {
            if (in_array($nodeId, ['start', 'end'])) {
                continue;
            }
            $node = $this->nodeById($graph, $nodeId);
            if (!$node) {
                continue;
            }
            $auditors = $this->nodeAuditorsForUser($node, $uid);
            if (!$auditors || in_array((string)$uid, array_map('strval', $auditors))) {
                $nodes[] = $node;
            }
        }
        return $nodes;
    }

    protected function historicalNodeIds($row, $uid)
    {
        $ids = $this->completedNodeIds($row);
        $stored = json_decode($row['form_data'], true) ?: [];
        foreach (array_keys($stored) as $nodeId) {
            $ids[] = $nodeId;
        }
        $logIds = \app\admin\model\tradeflow\Log::where('instance_id', $row['id'])
            ->where('operator_id', $uid)
            ->column('node_id');
        foreach ($logIds as $nodeId) {
            $ids[] = $nodeId;
        }
        return array_values(array_unique(array_filter($ids)));
    }

    protected function mergeNodes($primary, $secondary)
    {
        $merged = [];
        $seen = [];
        foreach (array_merge($primary, $secondary) as $node) {
            if (!isset($node['id']) || isset($seen[$node['id']])) {
                continue;
            }
            $seen[$node['id']] = true;
            $merged[] = $node;
        }
        return $merged;
    }

    protected function activeSummary($nodes)
    {
        $ids = [];
        $names = [];
        $auditors = [];
        foreach ($nodes as $node) {
            $ids[] = $node['id'];
            $names[] = $node['label'];
            $auditors = array_merge($auditors, $this->nodeAuditors($node));
        }
        $auditors = array_values(array_unique(array_filter($auditors)));
        return [
            'ids' => implode(',', $ids),
            'names' => implode('、', $names),
            'auditor_ids' => implode(',', $auditors),
        ];
    }

    protected function completedNodeIds($row)
    {
        return array_values(array_filter(explode(',', (string)$this->modelValue($row, 'completed_node_ids', ''))));
    }

    protected function modelValue($row, $key, $default = '')
    {
        $data = $row instanceof \think\Model ? $row->getData() : (array)$row;
        return array_key_exists($key, $data) ? $data[$key] : $default;
    }

    protected function saveInstance($row, $data)
    {
        $fields = Db::name('tradeflow_instance')->getTableFields();
        if ($fields) {
            $data = array_intersect_key($data, array_flip($fields));
        }
        return $row->save($data);
    }

    protected function nodeById($graph, $id)
    {
        foreach ($graph['nodes'] as $node) {
            if ($node['id'] === $id) {
                return $node;
            }
        }
        return null;
    }

    protected function nodeAuditors($node)
    {
        if ($node['type'] === 'form') {
            return [$this->adminId()];
        }
        return isset($node['reviewer_ids']) ? array_filter($node['reviewer_ids']) : [];
    }

    protected function nodeAuditorsForUser($node, $uid)
    {
        if ($node['type'] === 'form') {
            return [$uid];
        }
        return isset($node['reviewer_ids']) ? array_filter($node['reviewer_ids']) : [];
    }

    protected function storedNodeData($row, $nodeId)
    {
        $stored = json_decode($row['form_data'], true) ?: [];
        return isset($stored[$nodeId]) && is_array($stored[$nodeId]) ? $stored[$nodeId] : [];
    }

    protected function fieldsWithValues($fields, $data)
    {
        foreach ($fields as &$field) {
            $name = isset($field['name']) ? $field['name'] : '';
            $field['value'] = $name !== '' && array_key_exists($name, $data) ? $data[$name] : '';
            if (isset($field['type']) && $field['type'] === 'array') {
                $field['rows'] = is_array($field['value']) && $field['value'] ? $field['value'] : [[]];
            }
        }
        return $fields;
    }

    protected function writeLog($instanceId, $nodeId, $nodeName, $nodeType, $operatorId, $action, $comment, $formData)
    {
        \app\admin\model\tradeflow\Log::create([
            'instance_id' => $instanceId,
            'node_id' => $nodeId,
            'node_name' => $nodeName,
            'node_type' => $nodeType,
            'operator_id' => $operatorId,
            'action' => $action,
            'comment' => $comment,
            'form_data' => json_encode($formData, JSON_UNESCAPED_UNICODE),
            'createtime' => time(),
        ]);
    }

    protected function writeCopies($instanceId, $node, $extraCcIds = [])
    {
        $receiverIds = array_merge(
            $this->normalizeIdList(isset($node['cc_ids']) ? $node['cc_ids'] : []),
            $this->normalizeIdList($extraCcIds)
        );
        $receiverIds = array_values(array_unique($receiverIds));
        if (!$receiverIds) {
            return;
        }
        foreach ($receiverIds as $receiverId) {
            $exists = \app\admin\model\tradeflow\Copy::where([
                'instance_id' => $instanceId,
                'node_id' => $node['id'],
                'receiver_id' => $receiverId,
            ])->find();
            if ($exists) {
                continue;
            }
            \app\admin\model\tradeflow\Copy::create([
                'instance_id' => $instanceId,
                'node_id' => $node['id'],
                'receiver_id' => $receiverId,
                'is_read' => 0,
                'createtime' => time(),
            ]);
        }
    }

    protected function requestCcIds()
    {
        $value = $this->request->request('cc_ids/a', [], null);
        $row = $this->request->request('row/a', [], null);
        if ((empty($value) && $value !== '0') && isset($row['cc_ids'])) {
            $value = $row['cc_ids'];
        }
        return $this->normalizeIdList($value);
    }

    protected function instanceCcIds($row)
    {
        $data = $row instanceof \think\Model ? $row->getData() : (array)$row;
        return $this->normalizeIdList(isset($data['cc_ids']) ? $data['cc_ids'] : '');
    }

    protected function normalizeIdList($value)
    {
        $items = [];
        foreach ((array)$value as $item) {
            if (is_array($item)) {
                $items = array_merge($items, $this->normalizeIdList($item));
                continue;
            }
            $parts = preg_split('/[,;\s]+/', (string)$item);
            foreach ($parts as $part) {
                $id = intval($part);
                if ($id > 0) {
                    $items[] = $id;
                }
            }
        }
        return array_values(array_unique($items));
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

    protected function ensureInstanceCcIdsField()
    {
        try {
            $fields = Db::name('tradeflow_instance')->getTableFields();
            if ($fields && !in_array('cc_ids', $fields)) {
                $table = config('database.prefix') . 'tradeflow_instance';
                Db::execute("ALTER TABLE `{$table}` ADD COLUMN `cc_ids` varchar(1000) NOT NULL DEFAULT '' COMMENT '实例额外抄送人' AFTER `current_auditor_ids`");
            }
        } catch (\Exception $e) {
        }
    }

    protected function readableFormItems($fields, $data)
    {
        $items = [];
        $knownKeys = [];
        foreach ($fields as $field) {
            $name = isset($field['name']) ? $field['name'] : '';
            if ($name === '' || !array_key_exists($name, $data)) {
                continue;
            }
            $knownKeys[$name] = true;
            $items[] = [
                'label' => isset($field['label']) ? $field['label'] : $name,
                'value' => $this->displayValue($data[$name], $field),
                'value_html' => $this->displayValueHtml($data[$name], $field),
            ];
        }
        foreach ($data as $key => $value) {
            if (!isset($knownKeys[$key]) && $key !== '') {
                $items[] = ['label' => $key, 'value' => $this->displayValue($value), 'value_html' => $this->escape($this->displayValue($value))];
            }
        }
        return $items;
    }

    protected function readableAllFormItems($fields, $data)
    {
        $items = [];
        $knownKeys = [];
        foreach ($fields as $field) {
            $name = isset($field['name']) ? $field['name'] : '';
            if ($name === '') {
                continue;
            }
            $knownKeys[$name] = true;
            $hasValue = array_key_exists($name, $data) && !$this->isEmptyValue($data[$name]);
            $items[] = [
                'label' => isset($field['label']) ? $field['label'] : $name,
                'value' => $hasValue ? $this->displayValue($data[$name], $field) : '未填写',
                'value_html' => $hasValue ? $this->displayValueHtml($data[$name], $field) : '<span class="text-muted">未填写</span>',
                'filled' => $hasValue ? 1 : 0,
            ];
        }
        foreach ($data as $key => $value) {
            if (!isset($knownKeys[$key]) && $key !== '') {
                $hasValue = !$this->isEmptyValue($value);
                $items[] = [
                    'label' => $key,
                    'value' => $hasValue ? $this->displayValue($value) : '未填写',
                    'value_html' => $hasValue ? $this->escape($this->displayValue($value)) : '<span class="text-muted">未填写</span>',
                    'filled' => $hasValue ? 1 : 0,
                ];
            }
        }
        return $items;
    }

    protected function detailNodes($row, $graph, $logsByNode)
    {
        $formData = json_decode($row['form_data'], true) ?: [];
        $activeIds = array_filter(explode(',', (string)$row['current_node_id']));
        $completedIds = $this->completedNodeIds($row);
        $items = [];
        foreach ($this->stages($graph) as $stageIndex => $stage) {
            foreach ($stage as $node) {
                $nodeId = isset($node['id']) ? $node['id'] : '';
                if ($nodeId === '') {
                    continue;
                }
                $fields = isset($node['form_fields']) ? $this->prepareFields($node['form_fields']) : [];
                $data = isset($formData[$nodeId]) && is_array($formData[$nodeId]) ? $formData[$nodeId] : [];
                $formItems = $this->readableAllFormItems($fields, $data);
                $filledCount = 0;
                foreach ($formItems as $formItem) {
                    if (!empty($formItem['filled'])) {
                        $filledCount++;
                    }
                }
                $status = 'pending';
                $statusText = '未开始';
                if (isset($logsByNode[$nodeId]) || in_array($nodeId, $completedIds)) {
                    $status = 'done';
                    $statusText = '已完成';
                } elseif (in_array($nodeId, $activeIds)) {
                    $status = 'active';
                    $statusText = '进行中';
                }
                if ($nodeId === 'start' && isset($logsByNode[$nodeId])) {
                    $status = 'done';
                    $statusText = '已发起';
                }
                if ($nodeId === 'end' && (int)$row['status'] === 1) {
                    $status = 'done';
                    $statusText = '已完成';
                }
                $items[] = [
                    'id' => $nodeId,
                    'label' => isset($node['label']) ? $node['label'] : $nodeId,
                    'type' => isset($node['type']) ? $node['type'] : '',
                    'type_text' => $this->nodeTypeText(isset($node['type']) ? $node['type'] : ''),
                    'stage' => $stageIndex,
                    'status' => $status,
                    'status_text' => $statusText,
                    'form_items' => $formItems,
                    'filled_count' => $filledCount,
                    'field_count' => count($formItems),
                    'logs' => isset($logsByNode[$nodeId]) ? $logsByNode[$nodeId] : [],
                ];
            }
        }
        return $items;
    }

    protected function isEmptyValue($value)
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!$this->isEmptyValue($item)) {
                    return false;
                }
            }
            return true;
        }
        return $value === null || $value === '';
    }

    protected function displayValue($value, $field = null)
    {
        if ($field && isset($field['type']) && $field['type'] === 'array' && is_array($value)) {
            $children = isset($field['children']) ? $field['children'] : [];
            $lines = [];
            $rowNumber = 1;
            foreach ($value as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $parts = [];
                foreach ($children as $child) {
                    $name = isset($child['name']) ? $child['name'] : '';
                    if ($name !== '' && isset($row[$name]) && $row[$name] !== '') {
                        $parts[] = (isset($child['label']) ? $child['label'] : $name) . '：' . $this->displayValue($row[$name]);
                    }
                }
                if (!$parts) {
                    foreach ($row as $key => $item) {
                        if ($item !== '') {
                            $parts[] = $key . '：' . $this->displayValue($item);
                        }
                    }
                }
                if ($parts) {
                    $lines[] = $rowNumber . '. ' . implode('；', $parts);
                    $rowNumber++;
                }
            }
            return implode("\n", $lines);
        }
        if (is_array($value)) {
            return implode('，', array_filter($value, function ($item) {
                return $item !== '';
            }));
        }
        return (string)$value;
    }

    protected function displayValueHtml($value, $field = null)
    {
        if ($field && isset($field['type']) && $field['type'] === 'file') {
            return $this->fileLinksHtml($value);
        }
        if ($field && isset($field['type']) && $field['type'] === 'array' && is_array($value)) {
            return $this->arrayValueHtml($value, isset($field['children']) ? $field['children'] : []);
        }
        return nl2br($this->escape($this->displayValue($value, $field)));
    }

    protected function arrayValueHtml($value, $children)
    {
        $rows = [];
        $rowNumber = 1;
        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }
            $parts = [];
            foreach ($children as $child) {
                $name = isset($child['name']) ? $child['name'] : '';
                if ($name === '' || !isset($row[$name]) || $this->isEmptyValue($row[$name])) {
                    continue;
                }
                $label = isset($child['label']) ? $child['label'] : $name;
                $html = isset($child['type']) && $child['type'] === 'file'
                    ? $this->fileLinksHtml($row[$name])
                    : nl2br($this->escape($this->displayValue($row[$name])));
                $parts[] = '<span class="tf-array-part"><strong>' . $this->escape($label) . '：</strong>' . $html . '</span>';
            }
            if (!$parts) {
                foreach ($row as $key => $item) {
                    if (!$this->isEmptyValue($item)) {
                        $parts[] = '<span class="tf-array-part"><strong>' . $this->escape($key) . '：</strong>' . nl2br($this->escape($this->displayValue($item))) . '</span>';
                    }
                }
            }
            if ($parts) {
                $rows[] = '<div class="tf-array-display-row"><span class="tf-array-row-index">' . $rowNumber . '.</span> ' . implode('； ', $parts) . '</div>';
                $rowNumber++;
            }
        }
        return $rows ? implode('', $rows) : '<span class="text-muted">未填写</span>';
    }

    protected function fileLinksHtml($value)
    {
        $files = $this->normalizeFileValues($value);
        if (!$files) {
            return '<span class="text-muted">未填写</span>';
        }
        $html = [];
        foreach ($files as $file) {
            $url = $this->publicFileUrl($file);
            $name = basename(parse_url($file, PHP_URL_PATH) ?: $file);
            $type = $this->previewType($file);
            $html[] = '<a href="' . $this->escape($url) . '" target="_blank" class="tf-file-preview" data-url="' . $this->escape($url) . '" data-type="' . $this->escape($type) . '" title="点击预览">' . $this->fileIcon($type) . ' ' . $this->escape($name) . '</a>';
        }
        return implode('<br>', $html);
    }

    protected function normalizeFileValues($value)
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $items = preg_split('/[,;\r\n]+/', (string)$value);
        }
        $files = [];
        foreach ((array)$items as $item) {
            $item = trim((string)$item);
            if ($item !== '') {
                $files[] = $item;
            }
        }
        return $files;
    }

    protected function publicFileUrl($file)
    {
        if (preg_match('/^https?:\/\//i', $file)) {
            return $file;
        }
        if (strpos($file, '//') === 0) {
            return $this->request->scheme() . ':' . $file;
        }
        return $this->request->domain() . '/' . ltrim($file, '/');
    }

    protected function previewType($file)
    {
        $path = parse_url($file, PHP_URL_PATH) ?: $file;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'])) {
            return 'image';
        }
        if ($ext === 'pdf') {
            return 'pdf';
        }
        if (in_array($ext, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'])) {
            return 'office';
        }
        return 'file';
    }

    protected function fileIcon($type)
    {
        $map = [
            'image' => '<i class="fa fa-file-image-o"></i>',
            'pdf' => '<i class="fa fa-file-pdf-o"></i>',
            'office' => '<i class="fa fa-file-word-o"></i>',
            'file' => '<i class="fa fa-paperclip"></i>',
        ];
        return isset($map[$type]) ? $map[$type] : $map['file'];
    }

    protected function escape($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    protected function prepareFields($fields)
    {
        foreach ($fields as &$field) {
            if (isset($field['type']) && $field['type'] === 'array') {
                if (!empty($field['children']) && is_array($field['children'])) {
                    $field['children'] = $this->normalizeArrayChildren($field['children']);
                } else {
                    $field['children'] = $this->parseArrayChildren(isset($field['options']) ? $field['options'] : []);
                }
                if (!isset($field['rows'])) {
                    $field['rows'] = [[]];
                }
            } else {
                $field['options'] = $this->normalizeOptions(isset($field['options']) ? $field['options'] : []);
            }
        }
        return $fields;
    }

    protected function normalizeArrayChildren($children)
    {
        $normalized = [];
        foreach ((array)$children as $index => $child) {
            $label = isset($child['label']) ? trim($child['label']) : '';
            if ($label === '') {
                continue;
            }
            $type = isset($child['type']) ? trim($child['type']) : 'text';
            if (!in_array($type, ['text', 'textarea', 'select', 'radio', 'checkbox', 'file', 'datetime'])) {
                $type = 'text';
            }
            $content = isset($child['content']) ? trim($child['content']) : '';
            $name = isset($child['name']) && $child['name'] !== '' ? $child['name'] : 'sub_' . substr(md5($label . '_' . $index), 0, 10);
            $normalized[] = [
                'label' => $label,
                'name' => $name,
                'type' => $type,
                'content' => $content,
                'options' => $this->normalizeOptions(isset($child['options']) ? $child['options'] : $content),
            ];
        }
        if (!$normalized) {
            $normalized[] = ['label' => '内容', 'name' => 'sub_content', 'type' => 'text', 'content' => '', 'options' => []];
        }
        return $normalized;
    }

    protected function parseArrayChildren($options)
    {
        $children = [];
        foreach ((array)$options as $index => $option) {
            $parts = explode(':', $option, 3);
            $label = trim($parts[0]);
            if ($label === '') {
                continue;
            }
            $type = isset($parts[1]) && trim($parts[1]) !== '' ? trim($parts[1]) : 'text';
            if (!in_array($type, ['text', 'textarea', 'select', 'radio', 'checkbox', 'file', 'datetime'])) {
                $type = 'text';
            }
            $children[] = [
                'label' => $label,
                'name' => 'sub_' . substr(md5($label . '_' . $index), 0, 10),
                'type' => $type,
                'content' => isset($parts[2]) ? trim($parts[2]) : '',
                'options' => $this->normalizeOptions(isset($parts[2]) ? trim($parts[2]) : ''),
            ];
        }
        if (!$children) {
            $children[] = ['label' => '内容', 'name' => 'sub_content', 'type' => 'text', 'content' => '', 'options' => []];
        }
        return $children;
    }

    protected function normalizeOptions($options)
    {
        if (is_string($options)) {
            $options = explode(',', $options);
        }
        $result = [];
        foreach ((array)$options as $option) {
            $option = trim((string)$option);
            if ($option !== '') {
                $result[] = $option;
            }
        }
        return $result;
    }

    protected function nodeTypeText($type)
    {
        $map = [
            'start' => '开始',
            'end' => '结束',
            'audit' => '审核节点',
            'form' => '表单节点',
        ];
        return isset($map[$type]) ? $map[$type] : $type;
    }

    protected function actionText($action)
    {
        $map = [
            'launch' => '发起',
            'approve' => '通过',
            'reject' => '拒绝',
            'finish' => '完成',
            'update' => '修改',
        ];
        return isset($map[$action]) ? $map[$action] : $action;
    }

    protected function adminId()
    {
        $admin = Session::get('admin');
        return isset($admin['id']) ? $admin['id'] : 0;
    }
}
