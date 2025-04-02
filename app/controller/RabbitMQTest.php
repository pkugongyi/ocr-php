<?php
namespace app\controller;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Exception\AMQPTimeoutException;

class RabbitMQTest
{
    public function produce()
    {
        // 连接到 RabbitMQ 服务器
        $connection = new AMQPStreamConnection('localhost', 5672, 'admin', 'Wechess20160513');
        $channel = $connection->channel();

        // 声明一个队列
        $channel->queue_declare('input', false, false, false, false);

        // 发送从 1 到 10 的消息，每条消息间隔 1 秒
        for ($i = 1; $i <= 10; $i++) {
            $timestamp = time(); // 获取 UNIX 时间戳
            $relative_image_path = "images/$i.jpg"; // 示例图片相对路径
            $absolute_image_path = realpath($relative_image_path); // 获取图片的绝对路径
            
            if ($absolute_image_path === false) {
                echo " [x] Error: Image path '$relative_image_path' does not exist.<br>";
                continue;
            }

            $md5_hash = md5($absolute_image_path); // 计算路径的 MD5 哈希值
            $data = json_encode(['image_path' => $absolute_image_path, 'md5' => $md5_hash, 'timestamp' => $timestamp]);
            $msg = new AMQPMessage($data);
            $channel->basic_publish($msg, '', 'input');
            echo " [x] Sent '$data'<br>";
            sleep(1); // 每条消息间隔 1 秒
        }

        // 关闭通道和连接
        $channel->close();
        $connection->close();
    }

    public function consume()
    {
        // 连接到 RabbitMQ 服务器
        $connection = new AMQPStreamConnection('localhost', 5672, 'admin', 'Wechess20160513');
        $channel = $connection->channel();

        // 声明一个队列
        $channel->queue_declare('input', false, false, false, false);

        while (true) {
            try {
                // 消费队列中的消息
                $msg = $channel->basic_get('input', true); // 使用自动确认模式
                if ($msg) {
                    $data = json_decode($msg->body, true);
                    echo " [x] Received image_path: ", $data['image_path'], ", md5: ", $data['md5'], ", timestamp: ", $data['timestamp'], "<br>";
                } else {
                    echo " [x] No more messages in the queue. Exiting.<br>";
                    break;
                }
            } catch (AMQPTimeoutException $e) {
                echo " [x] Timeout while waiting for messages. Exiting.<br>";
                break;
            }
            sleep(1); // 每条消息间隔 1 秒
        }

        // 关闭通道和连接
        $channel->close();
        $connection->close();
    }
}