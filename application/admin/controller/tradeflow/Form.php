<?php

namespace app\admin\controller\tradeflow;

use app\common\controller\Backend;

class Form extends Backend
{
    protected $model = null;

    public function _initialize()
    {
        parent::_initialize();
        $this->model = new \app\admin\model\tradeflow\Form;
        $this->view->assign('statusList', $this->model->getStatusList());
    }

    public function add()
    {
        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            $params['fields_data'] = $this->normalizeFields($this->request->post('fields_data', '[]', null));
            $this->model->allowField(true)->save($params);
            $this->success();
        }
        return $this->view->fetch();
    }

    public function edit($ids = null)
    {
        $row = $this->model->get($ids);
        if (!$row) {
            $this->error(__('No Results were found'));
        }
        if ($this->request->isPost()) {
            $params = $this->request->post('row/a');
            $params['fields_data'] = $this->normalizeFields($this->request->post('fields_data', '[]', null));
            $row->allowField(true)->save($params);
            $this->success();
        }
        $this->view->assign('row', $row);
        return $this->view->fetch();
    }

    protected function normalizeFields($json)
    {
        $fields = json_decode($json, true);
        if (!is_array($fields)) {
            $fields = [];
        }
        foreach ($fields as $index => &$field) {
            $field['label'] = isset($field['label']) ? trim($field['label']) : '';
            $field['name'] = isset($field['name']) && $field['name'] !== '' ? trim($field['name']) : 'field_' . ($index + 1);
            $field['type'] = isset($field['type']) ? trim($field['type']) : 'text';
            $field['options'] = isset($field['options']) && is_array($field['options']) ? array_values($field['options']) : [];
            $field['required'] = !empty($field['required']) ? 1 : 0;
            if ($field['type'] === 'array') {
                $children = isset($field['children']) && is_array($field['children']) ? $field['children'] : [];
                foreach ($children as $childIndex => &$child) {
                    $child['label'] = isset($child['label']) ? trim($child['label']) : '';
                    $child['name'] = isset($child['name']) && $child['name'] !== '' ? trim($child['name']) : 'sub_' . ($childIndex + 1);
                    $child['type'] = isset($child['type']) ? trim($child['type']) : 'text';
                    $child['content'] = isset($child['content']) ? trim($child['content']) : '';
                    $child['options'] = isset($child['options']) && is_array($child['options']) ? array_values(array_filter($child['options'], function ($option) {
                        return trim((string)$option) !== '';
                    })) : [];
                    if (!empty($child['options'])) {
                        $child['content'] = implode(',', $child['options']);
                    }
                }
                unset($child);
                $field['children'] = array_values(array_filter($children, function ($child) {
                    return isset($child['label']) && $child['label'] !== '';
                }));
                $field['options'] = [];
            }
        }
        unset($field);
        return json_encode(array_values($fields), JSON_UNESCAPED_UNICODE);
    }
}
