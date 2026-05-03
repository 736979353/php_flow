<?php

namespace app\admin\model\tradeflow;

use think\Model;

class Form extends Model
{
    protected $name = 'tradeflow_form';
    protected $autoWriteTimestamp = 'int';
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';
    protected $deleteTime = false;

    protected $append = ['status_text'];

    public function getStatusList()
    {
        return ['0' => '禁用', '1' => '启用'];
    }

    public function getStatusTextAttr($value, $data)
    {
        $list = $this->getStatusList();
        return isset($list[$data['status']]) ? $list[$data['status']] : '';
    }
}
