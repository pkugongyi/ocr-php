<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
use think\facade\Route;

Route::get('think', function () {
    return 'hello,ThinkPHP6!';
});

Route::get('hello/:name', 'index/hello');
Route::get('test', 'Test/index');
Route::get('produce', 'RabbitMQTest/produce');
Route::get('consume', 'RabbitMQTest/consume');
Route::get('upload', 'Upload/index'); // 显示上传表单
Route::post('upload', 'Upload/uploadImage'); // 处理上传
Route::get('run', 'Consumer/run'); //模拟返回FEN