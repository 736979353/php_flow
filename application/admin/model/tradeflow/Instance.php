<?php

namespace app\admin\model\tradeflow;

use app\admin\model\Admin;
use think\Model;

class Instance extends Model
{
    protected $name = 'tradeflow_instance';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $deleteTime = false;

    protected $append = [
        'status_text',
        'initiator_name',
    ];

    public function getStatusList()
    {
        return ['0' => '进行中', '1' => '已完成', '2' => '已拒绝', '3' => '已撤销'];
    }

    public function getStatusTextAttr($value, $data)
    {
        $list = $this->getStatusList();
        return isset($list[$data['status']]) ? $list[$data['status']] : '';
    }

    public function getInitiatorNameAttr($value, $data)
    {
        $admin = Admin::get(isset($data['initiator_id']) ? $data['initiator_id'] : 0);
        return $admin ? $admin['nickname'] : '';
    }
}
