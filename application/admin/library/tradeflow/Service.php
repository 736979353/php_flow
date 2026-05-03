<?php

namespace app\admin\library\tradeflow;

use app\admin\model\tradeflow\Copy;
use app\admin\model\tradeflow\Flow;
use app\admin\model\tradeflow\Instance;
use app\admin\model\tradeflow\Log;
use think\Db;
use think\Exception;

class Service
{
    public static function launch($flowId, array $data)
    {
        return (new self)->createInstance($flowId, $data);
    }

    public function createInstance($flowId, array $data)
    {
        $this->ensureInstanceCcIdsField();

        $flow = Flow::get($flowId);
        if (!$flow || (int)$flow['status'] !== 1) {
            throw new Exception('流程不存在或未启用');
        }
        $exists = Instance::where('flow_id', $flow['id'])->order('id desc')->find();
        if ($exists) {
            throw new Exception('该流程设计已发起过流程办理，不能重复发起');
        }

        $graph = json_decode($flow['graph'], true);
        if (!$graph) {
            throw new Exception('流程尚未设计');
        }

        $title = isset($data['title']) ? trim($data['title']) : '';
        if ($title === '') {
            throw new Exception('流程标题不能为空');
        }
        $initiatorId = isset($data['initiator_id']) ? (int)$data['initiator_id'] : 0;
        if (!$initiatorId) {
            throw new Exception('发起人不能为空');
        }
        $formData = isset($data['form_data']) && is_array($data['form_data']) ? $data['form_data'] : [];
        $ccIds = $this->normalizeIdList(isset($data['cc_ids']) ? $data['cc_ids'] : []);

        $firstStage = $this->stageAfter($graph, 0);
        if (!$firstStage || $this->isEndStage($firstStage)) {
            throw new Exception('流程缺少开始后的办理节点');
        }
        $summary = $this->activeSummary($firstStage, $initiatorId);
        $instanceData = [
            'flow_id' => $flow['id'],
            'title' => $title,
            'initiator_id' => $initiatorId,
            'current_node_id' => $summary['ids'],
            'current_node_name' => $summary['names'],
            'current_auditor_ids' => $summary['auditor_ids'],
            'cc_ids' => implode(',', $ccIds),
            'status' => 0,
            'active_nodes_data' => json_encode($firstStage, JSON_UNESCAPED_UNICODE),
            'completed_node_ids' => 'start',
            'graph_snapshot' => json_encode($graph, JSON_UNESCAPED_UNICODE),
            'form_data' => json_encode(['start' => $formData], JSON_UNESCAPED_UNICODE),
        ];

        Db::startTrans();
        try {
            $instance = new Instance;
            $instance->allowField(true)->save($instanceData);
            $this->writeLog($instance['id'], 'start', '开始', 'start', $initiatorId, 'launch', '发起流程', $formData);
            foreach ($firstStage as $node) {
                $this->writeCopies($instance['id'], $node, $ccIds);
            }
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }

        return $instance;
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
        unset($stage);
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

    protected function isEndStage($stage)
    {
        return count($stage) === 1 && isset($stage[0]['type']) && $stage[0]['type'] === 'end';
    }

    protected function activeSummary($nodes, $initiatorId)
    {
        $ids = [];
        $names = [];
        $auditors = [];
        foreach ($nodes as $node) {
            $ids[] = $node['id'];
            $names[] = $node['label'];
            $auditors = array_merge($auditors, $this->nodeAuditors($node, $initiatorId));
        }
        $auditors = array_values(array_unique(array_filter($auditors)));
        return [
            'ids' => implode(',', $ids),
            'names' => implode('、', $names),
            'auditor_ids' => implode(',', $auditors),
        ];
    }

    protected function nodeById($graph, $id)
    {
        foreach (isset($graph['nodes']) ? $graph['nodes'] : [] as $node) {
            if ($node['id'] === $id) {
                return $node;
            }
        }
        return null;
    }

    protected function nodeAuditors($node, $initiatorId)
    {
        if ($node['type'] === 'form') {
            return [$initiatorId];
        }
        return isset($node['reviewer_ids']) ? array_filter($node['reviewer_ids']) : [];
    }

    protected function writeLog($instanceId, $nodeId, $nodeName, $nodeType, $operatorId, $action, $comment, $formData)
    {
        Log::create([
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
        foreach ($receiverIds as $receiverId) {
            $exists = Copy::where([
                'instance_id' => $instanceId,
                'node_id' => $node['id'],
                'receiver_id' => $receiverId,
            ])->find();
            if ($exists) {
                continue;
            }
            Copy::create([
                'instance_id' => $instanceId,
                'node_id' => $node['id'],
                'receiver_id' => $receiverId,
                'is_read' => 0,
                'createtime' => time(),
            ]);
        }
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

    protected function ensureInstanceCcIdsField()
    {
        $fields = Db::name('tradeflow_instance')->getTableFields();
        if ($fields && !in_array('cc_ids', $fields)) {
            $table = config('database.prefix') . 'tradeflow_instance';
            Db::execute("ALTER TABLE `{$table}` ADD COLUMN `cc_ids` varchar(1000) NOT NULL DEFAULT '' COMMENT '实例额外抄送人' AFTER `current_auditor_ids`");
        }
    }
}
