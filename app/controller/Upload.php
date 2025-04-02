<?php
namespace app\controller;

use think\Request;
use app\model\Images;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class Upload
{
    // 显示上传表单
    public function index()
    {
        return view('index'); // 渲染 index.html 视图
    }

    // 图片上传方法
    public function uploadImage(Request $request)
    {
        // 将连接参数存储在变量中
        $host = 'localhost';  // RabbitMQ 主机地址
        $port = 5672;         // RabbitMQ 端口
        $username = 'admin';  // 用户名
        $password = 'Wechess20160513';  // 密码
        $vhost = '/';  // 虚拟主机，默认为 "/"
        
        // 使用变量建立 RabbitMQ 连接
        $connection = new AMQPStreamConnection($host, $port, $username, $password, $vhost);
        

        // 获取上传的文件
        $file = $request->file('image');

        // 检查文件是否存在
        if ($file) {
            // 获取当前日期
            $date = date('Ymd');

            // 指定上传目录，并包含日期子目录
            $uploadDir = root_path() . 'public/images/' . $date . '/';

            // 创建目录（如果不存在）
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            // 获取文件的扩展名
            $extension = $file->extension();

            // 生成32位唯一的文件名，不带后缀
            $uniqueFileName = md5(uniqid(random_bytes(16), true));
            $uniqueFileNameWithExtension = $uniqueFileName . '.' . $extension;

            // 保存文件到指定目录
            $filePath = $uploadDir . $uniqueFileNameWithExtension;
            $file->move($uploadDir, $uniqueFileNameWithExtension);

            // 检查上传结果
            if (file_exists($filePath)) {
                // 构造数据以写入数据库
                $data_in = [
                    'image_path' => $filePath,
                    'time_stamp_in' => time()
                ];

                // 将数据写入数据库并获取主键ID
                $images = new Images();
                $images->save($data_in);
                $data_in['id'] = $images->id;  // 获取 id

                // 发送消息到 RabbitMQ，并将 id 作为 correlation_id
                $correlationId = $this->sendMessageToRabbitMQ($connection,$data_in); // 直接传递数据

                if ($correlationId) {
                    // 查询并读取消息队列中的数据,10s超时，每0.1秒重试一次
                    $data_out = $this->getMessageFromQueue($connection,$correlationId, 10, 100);
                    error_log("fen 的实际值：" . var_export($data_out['data']['fen'], true));


                    if ($data_out['status'] === 'failed' ||
                        !isset($data_out['data']['fen']) ||
                        $data_out['data']['fen'] === null ||
                        trim($data_out['data']['fen']) === '' || $data_out['data']['fen'] === 'None') {
                        // 处理失败或 fen 为 'None' 的情况
                        error_log("识别错误：" . $data_out['data']['fen']);
                        $failedDir = root_path() . 'public/failed/';
                        if (!is_dir($failedDir)) {
                            mkdir($failedDir, 0755, true);
                        }

                        $failedFileName = basename($filePath);
                        $failedFilePath = $failedDir . $failedFileName;

                        if (rename($filePath, $failedFilePath)) {
                            error_log("失败的图片已移动到: " . $failedFilePath);
                        } else {
                            error_log("移动失败的图片到 {$failedFilePath} 时出现问题。");
                        }
                    } elseif ($data_out['status'] === 'success') {
                        // 处理成功情况
                        error_log("主程序输出的data.id" . $data_out['id']);
                        $fen_out = Images::find($data_out['id']);
                        if ($fen_out) {
                            $fen_out->status_out = $data_out['status'];
                            $fen_out->fen_out = $data_out['data']['fen'];
                            $fen_out->is_reversed_out = $data_out['data']['is_reversed'];
                            $fen_out->confidence_out = $data_out['data']['confidence'];
                            $fen_out->time_stamp_out = $data_out['data']['time_stamp_out'];
                            $fen_out->time_consumed_out = $data_out['data']['time_consumed'];
                            $fen_out->message_out = $data_out['message'];
                            $fen_out->save();
                        }
                    }

                    // 返回消息队列中的数据
                    return json([
                        'status' => $data_out['status'],
                        'id' => $correlationId,
                        'data' => $data_out['data'],
                        'message' => $data_out['message']
                    ]);
                } else {
                    // 发送消息失败，返回错误响应并包含绝对的 image_path
                    return [
                        'status' => 'error',
                        'id' => $data_in['id'], // 使用 id 作为 correlation_id
                        'data' => [
                            'image_path' => $filePath, // 包含绝对路径
                            'fen' => '',
                            'is_reversed' => 0,
                            'confidence' => 0,
                            'time_stamp_out' => time(),
                            'time_consumed' => 0
                        ],
                        'message' => '发送消息到队列失败。'
                    ];
                }
            } else {
                // 返回上传失败的JSON响应
                return [
                    'status' => 'error',
                    'id' => null,
                    'data' => [
                        'image_path' => '',
                        'fen' => '',
                        'is_reversed' => 0,
                        'confidence' => 0,
                        'time_stamp_out' => time(),
                        'time_consumed' => 0
                    ],
                    'message' => '文件上传失败。'
                ];
            }
        } else {
            // 返回上传失败的JSON响应，没有 id 可用
            return [
                'status' => 'error',
                '' => null,
                'data' => [
                    'image_path' => '',
                    'fen' => '',
                    'is_reversed' => 0,
                    'confidence' => 0,
                    'time_stamp_out' => time(),
                    'time_consumed' => 0
                ],
                'message' => '未上传文件。'
            ];
        }
    }


    // 发送消息到RabbitMQ，返回 correlation_id
    private function sendMessageToRabbitMQ($connection,$message)
    {
        try {
            // 创建频道
            $channel = $connection->channel();
            // 声明输入队列，durable=true
            $channel->queue_declare('queue_input', false, false, false, false);
    
            // 从消息中获取 id
            $correlationId = $message['id'];  // 直接从数组中获取 id
    
            // 根据 id 动态生成输出队列名
            $outputQueue = 'queue_output_' . $correlationId;
    
            // 声明动态生成的输出队列，设置 auto_delete=false
            $channel->queue_declare($outputQueue, false, false, false, false);  // auto_delete=false
    
            // 构造消息
            $msg = new AMQPMessage(json_encode($message), [
                'correlation_id' => $correlationId,
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
            ]);
    
            // 发送消息到输入队列
            $channel->basic_publish($msg, '', 'queue_input');
            error_log("已发送消息到 queue_input 队列，Correlation ID: {$correlationId}");
    
            // 关闭通道和连接
            $channel->close();
            $connection->close();
    
            // 返回 correlation_id
            return $correlationId;
        } catch (\Exception $e) {
            // 记录异常日志
            error_log('sendMessageToRabbitMQ 异常: ' . $e->getMessage());
            return null;
        }
    }


    // 根据 correlation_id 读取消息队列中的数据，带有轮询机制
    private function getMessageFromQueue($connection, $correlationId, $timeout = 10, $interval = 1000) // interval 默认值改为 1000毫秒
    {
        try {
            // 创建频道
            $channel = $connection->channel();
            // 根据 correlationId 动态生成输出队列名称
            $outputQueue = 'queue_output_' . $correlationId;
    
            // 声明输出队列，auto_delete=false 使队列在使用后不会自动删除
            $channel->queue_declare($outputQueue, false, false, false, false);

            $outputData = null;
            $messageData = null;
            $startTime = microtime(true);
            $endTime = $startTime + $timeout;
            $attempt = 0;
    
            while (microtime(true) < $endTime) {
                $attempt++;
                error_log("轮询尝试 #{$attempt} at " . date('Y-m-d H:i:s'));
    
                // 尝试获取一条消息，auto_ack设置为false
                $msg = $channel->basic_get($outputQueue, false);
    
                if ($msg) {
                    // 输出消息内容用于调试
                    error_log("收到的原始消息: " . $msg->body);
    
                    $data = json_decode($msg->body, true);
                    if ($data === null) {
                        error_log("JSON 解码失败，错误: " . json_last_error_msg());
                        continue;
                    }
    
                    if (isset($data['id']) && $data['id'] == $correlationId) {
                        // 找到匹配的消息，确认并返回
                        $channel->basic_ack($msg->delivery_info['delivery_tag']);
                        error_log("找到匹配的消息，Correlation ID: {$correlationId}");
                        $outputData = $data;
                        $messageData = $data['data']; // 假设处理后的数据在 'data' 字段
                        error_log("FEN: {$messageData['fen']}");
                        break;
                    } else {
                        // 消息不匹配，确认以移除消息
                        $channel->basic_ack($msg->delivery_info['delivery_tag']);
                        if ($data && isset($data['id'])) {
                            error_log("消息Correlation ID不匹配，当前Correlation ID: " . $data['id']);
                        } else {
                            error_log("收到无效的消息格式或缺少Correlation ID字段。");
                        }
                    }
                } else {
                    error_log("当前没有消息可供获取。");
                }
    
                // 等待指定的时间间隔再进行下一次轮询 (转换为微秒)
                usleep($interval * 1000); // 毫秒转换为微秒
    
                // 记录剩余时间
                $remainingTime = round($endTime - microtime(true), 2);
                error_log("剩余时间: {$remainingTime} 秒");
            }
    
            // 删除消息队列
            $channel->queue_delete($outputQueue);
    
            // 关闭通道和连接
            $channel->close();
            $connection->close();
    
            // 返回结果
            if ($messageData) {
                error_log("返回数据的FEN: {$messageData['fen']}");
                return [
                    'status' => $outputData['status'],
                    'id' => $correlationId,
                    'data' => $messageData,
                    'message' => $outputData['message']
                ];
            } else {
                error_log("在超时时间内未找到对应的消息，Correlation ID: {$correlationId}");
                return [
                    'status' => 'error',
                    'id' => $correlationId,
                    'data' => [
                        'image_path' => '',
                        'fen' => '',
                        'is_reversed' => 0,
                        'confidence' => 0,
                        'time_stamp_out' => time(),
                        'time_consumed' => 0
                    ],
                    'message' => '在超时时间内未找到对应的消息。'
                ];
            }
        } catch (\Exception $e) {
            // 记录异常日志
            error_log('getMessageFromQueue 异常: ' . $e->getMessage());
    
            // 如果发生异常，返回错误信息
            return [
                'status' => 'error',
                'id' => $correlationId,
                'data' => [
                    'image_path' => '',
                    'fen' => '',
                    'is_reversed' => 0,
                    'confidence' => 0,
                    'time_stamp_out' => time(),
                    'time_consumed' => 0
                ],
                'message' => '在处理消息时发生异常。'
            ];
        }
    }


}
