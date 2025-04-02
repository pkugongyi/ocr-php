<?php
namespace app\controller;

use think\Request;
use app\model\Images;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class Consumer
{
    // 现有的方法...

    /**
     * 消费者方法，用于处理 queue_input 队列中的消息
     */
    public function run()
    {
        try {
            // 创建 RabbitMQ 连接
            $connection = new AMQPStreamConnection('localhost', 5672, 'admin', 'Wechess20160513', '/');
            $channel = $connection->channel();

            // 声明输入队列，确保 durable 参数一致
            $channel->queue_declare('queue_input', false, true, false, false);

            error_log("等待接收消息...\n");

            // 设置预取计数，确保每次只处理一条消息
            $channel->basic_qos(null, 1, null);

            // 消费消息的回调函数
            $callback = function (AMQPMessage $msg) {
                error_log("接收到消息: " . $msg->body . "\n");
                $data = json_decode($msg->body, true);

                // 检查消息格式
                if (!isset($data['id'])) {
                    error_log("消息缺少 'id' 字段，忽略该消息。\n");
                    $msg->ack();
                    return;
                }

                // 获取消息属性
                $properties = $msg->get_properties();
                $correlationId = isset($properties['correlation_id']) ? $properties['correlation_id'] : null;
                $replyTo = isset($properties['reply_to']) ? $properties['reply_to'] : null;

                error_log("Correlation ID: {$correlationId}\n");
                error_log("Reply To: {$replyTo}\n");

                if (!$correlationId || !$replyTo) {
                    error_log("消息缺少 'correlation_id' 或 'reply_to' 字段，忽略该消息。\n");
                    $msg->ack();
                    return;
                }

                // 判断图像文件是否存在
                $imagePath = isset($data['image_path']) ? $data['image_path'] : null;
                if (!$imagePath || !file_exists($imagePath) || !is_file($imagePath)) {
                    error_log("图像文件不存在或路径无效: {$imagePath}\n");

                    // 准备错误响应
                    $outputData = [
                        'status' => 'error',
                        'id' => $correlationId,
                        'data' => [
                            'image_path' => $imagePath,
                            'fen' => '',
                            'is_reversed' => 0,
                            'confidence' => 0.0,
                            'time_stamp_out' => time(),
                            'time_consumed' => 0
                        ],
                        'message' => '文件不存在。'
                    ];

                    // 发送响应到 reply_to 队列
                    $this->sendResponse($outputData, $replyTo, $correlationId);

                    // 确认消息
                    $msg->ack();
                    return;
                }

                // 模拟处理
                error_log("开始处理消息 ID: {$data['id']}\n");
                sleep(1); // 模拟处理时间

                // 构造处理后的消息，保留原始 'correlation_id'
                $processedData = [
                    'status' => 'success',
                    'id' => $correlationId,
                    'data' => [
                        'id' => $data['id'],
                        'image_path' => $data['image_path'],
                        'fen' => 'rnbakabnr/9/1c5c1/p1p1p1p1p/9/9/P1P1P1P1P/1C5C1/9/RNBAKABNR w - - 0 1',
                        'is_reversed' => 1,
                        'confidence' => 0.98,
                        'time_stamp_out' => time(),
                        'time_consumed' => 2,
                        'message' => '处理完成'
                    ]
                ];

                // 创建响应消息
                $processedMsg = new AMQPMessage(json_encode($processedData), [
                    'correlation_id' => $correlationId, // 保留 correlation_id
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
                ]);

                // 发布响应消息到 reply_to 队列
                $msg->delivery_info['channel']->basic_publish($processedMsg, '', $replyTo);

                error_log("处理完成，发送到 reply_to 队列，ID: {$data['id']}\n");

                // 确认消息已处理
                $msg->ack();
            };

            // 设置消费参数，no_ack=false 以手动确认
            $channel->basic_consume('queue_input', '', false, false, false, false, $callback);

            // 持续监听队列
            while ($channel->is_consuming()) {
                $channel->wait();
            }

            // 关闭通道和连接
            $channel->close();
            $connection->close();
        } catch (\Exception $e) {
            error_log('Consumer 异常: ' . $e->getMessage() . "\n");
        }
    }

    /**
     * 发送响应消息到 reply_to 队列
     *
     * @param array $outputData 要发送的数据
     * @param string $replyQueue reply_to 队列名
     * @param string $correlationId correlation_id
     */
    private function sendResponse($outputData, $replyQueue, $correlationId)
    {
        try {
            // 创建新的连接用于发送响应
            $responseConnection = new AMQPStreamConnection(
                'localhost',
                5672,
                'admin',
                'Wechess20160513',
                '/'
            );
            $responseChannel = $responseConnection->channel();

            // 确保 reply_to 队列存在
            $responseChannel->queue_declare(
                $replyQueue,
                false,  // passive
                false,  // durable (通常临时队列不需要持久化)
                false,  // exclusive
                false   // auto_delete
            );

            // 序列化输出数据为 JSON 字符串
            $responseBody = json_encode($outputData);

            // 创建响应消息，包含 correlation_id
            $responseMsg = new AMQPMessage(
                $responseBody,
                [
                    'correlation_id' => $correlationId,
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
                ]
            );

            // 发布消息到 reply_to 队列
            $responseChannel->basic_publish(
                $responseMsg,
                '',           // exchange
                $replyQueue    // routing_key
            );

           error_log("已发送响应消息到 '{$replyQueue}': {$responseBody}\n");

            // 关闭响应通道和连接
            $responseChannel->close();
            $responseConnection->close();
        } catch (\Exception $e) {
            error_log("发送响应消息失败: " . $e->getMessage() . "\n");
        }
    }

}
