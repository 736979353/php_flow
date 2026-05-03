<?php

$fullAccessGroupIds = '2,3';
$groupList = [];
try {
    $groups = \think\Db::name('auth_group')
        ->field('id,pid,name')
        ->where('status', 'normal')
        ->order('id asc')
        ->select();
    $children = [];
    foreach ($groups as $group) {
        $children[(int)$group['pid']][] = $group;
    }
    $walk = function ($pid, $level) use (&$walk, &$groupList, $children) {
        if (empty($children[$pid])) {
            return;
        }
        $count = count($children[$pid]);
        foreach ($children[$pid] as $index => $group) {
            $prefix = '';
            if ($level > 0) {
                $prefix = str_repeat('　', $level - 1) . (($index + 1) === $count ? '└ ' : '├ ');
            }
            $groupList[(int)$group['id']] = $prefix . $group['name'];
            $walk((int)$group['id'], $level + 1);
        }
    };
    $walk(0, 0);
} catch (\Exception $e) {
    $groupList = [];
}

return [
    [
        'name' => '__tips__',
        'title' => '权限说明',
        'type' => 'text',
        'content' => [],
        'value' => '选择“全量查看用户组”后，该用户组内的管理员本人可以查看所有流程设计；该能力不会自动给他的下级，下级仍需通过流程分配后才能看到。',
        'rule' => '',
        'msg' => '',
        'tip' => '',
        'ok' => '',
        'extend' => 'alert-info-light',
    ],
    [
        'name' => 'full_access_group_ids',
        'title' => '全量查看用户组',
        'type' => 'selects',
        'content' => $groupList,
        'value' => $fullAccessGroupIds,
        'rule' => '',
        'msg' => '',
        'tip' => '选中的用户组内管理员可查看所有流程设计，不向其下级继承。',
        'ok' => '',
        'extend' => 'data-live-search="true"',
    ],
];
