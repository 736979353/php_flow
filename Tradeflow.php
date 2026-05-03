<?php

namespace addons\tradeflow;

use app\common\library\Menu;
use think\Addons;
use think\Db;

class Tradeflow extends Addons
{
    protected $menu = [
        [
            'name' => 'tradeflow',
            'title' => '交易流程',
            'icon' => 'fa fa-random',
            'ismenu' => 1,
            'weigh' => 10,
            'sublist' => [
                [
                    'name' => 'tradeflow/form',
                    'title' => '表单模板',
                    'icon' => 'fa fa-wpforms',
                    'ismenu' => 1,
                    'weigh' => 11,
                    'sublist' => [
                        ['name' => 'tradeflow/form/index', 'title' => '查看'],
                        ['name' => 'tradeflow/form/add', 'title' => '添加'],
                        ['name' => 'tradeflow/form/edit', 'title' => '编辑'],
                        ['name' => 'tradeflow/form/del', 'title' => '删除'],
                        ['name' => 'tradeflow/form/multi', 'title' => '批量更新'],
                    ],
                ],
                [
                    'name' => 'tradeflow/flow',
                    'title' => '流程设计',
                    'icon' => 'fa fa-sitemap',
                    'ismenu' => 1,
                    'weigh' => 10,
                    'sublist' => [
                        ['name' => 'tradeflow/flow/index', 'title' => '查看'],
                        ['name' => 'tradeflow/flow/add', 'title' => '添加'],
                        ['name' => 'tradeflow/flow/edit', 'title' => '编辑'],
                        ['name' => 'tradeflow/flow/del', 'title' => '删除'],
                        ['name' => 'tradeflow/flow/multi', 'title' => '批量更新'],
                        ['name' => 'tradeflow/flow/designer', 'title' => '可视化设计'],
                        ['name' => 'tradeflow/flow/savegraph', 'title' => '保存设计'],
                    ],
                ],
                [
                    'name' => 'tradeflow/instance',
                    'title' => '流程办理',
                    'icon' => 'fa fa-tasks',
                    'ismenu' => 1,
                    'weigh' => 9,
                    'sublist' => [
                        ['name' => 'tradeflow/instance/index', 'title' => '查看'],
                        ['name' => 'tradeflow/instance/launch', 'title' => '发起流程'],
                        ['name' => 'tradeflow/instance/approve', 'title' => '办理节点'],
                        ['name' => 'tradeflow/instance/detail', 'title' => '流程详情'],
                        ['name' => 'tradeflow/instance/del', 'title' => '删除'],
                        ['name' => 'tradeflow/instance/multi', 'title' => '批量更新'],
                    ],
                ],
            ],
        ],
    ];

    public function install()
    {
        Menu::create($this->menu);
        return true;
    }

    public function uninstall()
    {
        Menu::delete('tradeflow');
        return true;
    }

    public function enable()
    {
        Menu::enable('tradeflow');
        return true;
    }

    public function disable()
    {
        Menu::disable('tradeflow');
        return true;
    }

    public function upgrade()
    {
        Menu::upgrade('tradeflow', $this->menu);
        return true;
    }

    public function config($name, $config)
    {
        $value = '';
        foreach ($config as $item) {
            if (isset($item['name']) && $item['name'] === 'full_access_group_ids') {
                $value = isset($item['value']) ? $item['value'] : '';
                break;
            }
        }
        $this->writeDynamicConfig($name, $value);
        \think\addons\Service::refresh();
    }

    protected function writeDynamicConfig($name, $fullAccessGroupIds)
    {
        $value = var_export((string)$fullAccessGroupIds, true);
        $content = <<<'PHP'
<?php

$fullAccessGroupIds = __FULL_ACCESS_GROUP_IDS__;
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
PHP;
        $content = str_replace('__FULL_ACCESS_GROUP_IDS__', $value, $content);
        file_put_contents(ADDON_PATH . $name . DS . 'config.php', $content, LOCK_EX);
    }

    protected function buildConfig($fullAccessGroupIds = '')
    {
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
                'content' => $this->groupTreeOptions(),
                'value' => $fullAccessGroupIds,
                'rule' => '',
                'msg' => '',
                'tip' => '选中的用户组内管理员可查看所有流程设计，不向其下级继承。',
                'ok' => '',
                'extend' => 'data-live-search="true"',
            ],
        ];
    }

    protected function groupTreeOptions()
    {
        $groups = Db::name('auth_group')
            ->field('id,pid,name')
            ->where('status', 'normal')
            ->order('id asc')
            ->select();
        $children = [];
        foreach ($groups as $group) {
            $children[(int)$group['pid']][] = $group;
        }

        $options = [];
        $walk = function ($pid, $level) use (&$walk, &$options, $children) {
            if (empty($children[$pid])) {
                return;
            }
            $count = count($children[$pid]);
            foreach ($children[$pid] as $index => $group) {
                $prefix = '';
                if ($level > 0) {
                    $prefix = str_repeat('　', $level - 1) . (($index + 1) === $count ? '└ ' : '├ ');
                }
                $options[(int)$group['id']] = $prefix . $group['name'];
                $walk((int)$group['id'], $level + 1);
            }
        };
        $walk(0, 0);

        return $options;
    }
}
