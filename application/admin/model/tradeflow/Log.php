<?php

namespace app\admin\model\tradeflow;

use app\admin\model\Admin;
use think\Model;

class Log extends Model
{
    protected $name = 'tradeflow_log';
    protected $autoWriteTimestamp = false;
    protected $createTime = false;
    protected $updateTime = false;
    protected $deleteTime = false;

    protected $append = ['operator_name'];

    public function getOperatorNameAttr($value, $data)
    {
        $admin = Admin::get(isset($data['operator_id']) ? $data['operator_id'] : 0);
        return $admin ? $admin['nickname'] : '';
    }
}
