<?php

namespace app\admin\model\tradeflow;

use app\admin\model\Admin;
use think\Model;

class Flow extends Model
{
    protected $name = 'tradeflow_flow';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $deleteTime = false;

    protected $append = [
        'status_text',
        'creator_name',
    ];

    public function getStatusList()
    {
        return ['0' => '草稿', '1' => '启用', '2' => '停用'];
    }

    public function getStatusTextAttr($value, $data)
    {
        $list = $this->getStatusList();
        return isset($list[$data['status']]) ? $list[$data['status']] : '';
    }

    public function getCreatorNameAttr($value, $data)
    {
        $admin = Admin::get(isset($data['createuser_id']) ? $data['createuser_id'] : 0);
        return $admin ? $admin['nickname'] : '';
    }
}
