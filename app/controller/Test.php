<?php
namespace app\controller;

use app\model\ImagesTest;

class Test
{
    public function index()
    {
        $data = [
            'image_path' => '111',
            'time_stamp_in' => time()
        ];
        
        $images = new ImagesTest();
        $images->save($data); // 保存用户数据

        // 获取插入的主键 ID
        $id = $images->id; // 这里假设主键字段名称为 'id'
        // 123456
        return json(['success' => true, 'id' => $id]);
    }
}