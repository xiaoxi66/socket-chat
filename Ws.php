<?php

/**
 * Class Ws
 *
 * 项目启动：
 * 1.开启xampp
 * 2.开启监听(开启服务端) php Ws.php
 * 3.打开浏览器 www.socket.com
 * 4.socket 在php的扩展
 */
class Ws {

    // 服务器端的socket
    private $mainSocket;
    // socket队列
    public $socketList = [];
    // 连接事件
    public $onConnection;
    // 会话事件
    public $onMessage;
    // 关闭事件
    public $onClose;


    // 构造函数
    public function __construct() {
        // 创建socket
        $this->mainSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        // IP和端口重用
        socket_set_option($this->mainSocket, SOL_SOCKET, SO_REUSEADDR, 1);
        // 绑定
        socket_bind($this->mainSocket, '0.0.0.0', 6060);
        // 监听
        socket_listen($this->mainSocket, 5);
        // 把服务器端的socket存入socket队列
        $this->socketList[(int)$this->mainSocket] = $this->mainSocket;
    }

    // 运行
    public function run() {

        // 让服务不停止
        while (true) {
            $read  = $this->socketList;
            $write = $except = null;
            // 删除不活跃的socket
            $status = socket_select($read, $write, $except, null);
            if (!$status) continue;

            foreach ($read as $socket) {
                // 第一次来访问
                if ($this->mainSocket == $socket) {
                    // 得到客户端的socket
                    $clientSocket = socket_accept($socket);
                    // 获取客户端头信息进行握手操作
                    $headData = socket_read($clientSocket, 8000);
                    handshaking($headData, $clientSocket);

                    socket_getpeername($clientSocket, $addr, $port);

                    // 把客户端的一个socket存入队列中
                    $this->socketList[(int)$clientSocket] = $clientSocket;
                    $read[(int)$clientSocket]             = $clientSocket;
                    // 删除掉
                    unset($read[(int)$this->mainSocket]);

                    // 注册一个事件
                    if ($this->onConnection) {
                        call_user_func($this->onConnection, $clientSocket);
                    }
                } else {
                    // 获得客户发送过来的数据
                    $buf = socket_read($socket, 8000);
                    // 关闭或异常
                    if ($buf === '' || $buf === false) {
                        // 删除掉队列它socket
                        unset($read[(int)$socket]);
                        unset($this->socketList[(int)$socket]);
                        if ($this->onClose){
                            call_user_func($this->onClose,$socket);
                        }
                        socket_close($socket);
                        continue;
                    }else{
                        if ($this->onMessage){
                            call_user_func($this->onMessage,$socket,$buf);
                        }
                    }
                }
            }//End foreach
        }// End while
    }//End run


}


$server = new Ws();

// 连接
$server->onConnection = function ($socket){
    $msg = (int)$socket."欢迎进入小希迷你聊天室 ！";
    socket_write($socket,enmask($msg));
};
// 会话
$server->onMessage = function ($socket,$data){
    $msg = unmask($data);
    // 广播给所有人 聊天室
    broadcast($msg,$socket);
};


$server->onclose = function ($socket){
  socket_close($socket);
};

$server->run();


/**
 * @param $msg 广播的消息
 * $msg string 消息内容
 * $socket_origin int 消息发送者id
 */
function broadcast($msg,$socket_origin){

    global $server;
    //遍历队列数组
    foreach ($server->socketList as $socket){

        if((int)$socket_origin == (int)$socket){
            $person = '自己说：';
            $flag = true;
        }else{
            $person = (int)$socket_origin.'说';
            $flag = false;
        }

//        $data = ['msg'=>$person.$msg,'is_self'=>$flag];
        //加密信息
        $tmp = enmask($person.$msg);
        //发送消息
        @socket_write($socket,$tmp);
    }
}


/**
 * @param $header       客户端头信息
 * @param $activeSocket 客户端的socket对象
 */
function handshaking($header, $activeSocket) {
    preg_match("/Sec\-WebSocket\-Key:\ (.+)\r\n/", $header, $matchs);
    $key  = base64_encode(sha1($matchs[1] . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));
    $head = "HTTP/1.1 101 Switching Protocols\r\n";
    $head .= "Upgrade: websocket\r\n"; // 告诉这个websocket客户端可以把这个协议升级为websocket协议
    $head .= "Connection: Upgrade\r\n"; // 升级这个通信协议
    $head .= "Sec-WebSocket-Accept: {$key}\r\n\r\n"; // 最后的一定要有两个回车
    // 握手响应协议信息返回给浏览器
    socket_write($activeSocket, $head);
}

//解码数据
function unmask($text) {
    $length = ord($text[1]) & 127;
    if ($length == 126) {
        $masks = substr($text, 4, 4);
        $data  = substr($text, 8);
    } elseif ($length == 127) {
        $masks = substr($text, 10, 4);
        $data  = substr($text, 14);
    } else {
        $masks = substr($text, 2, 4);
        $data  = substr($text, 6);
    }
    $text = "";
    for ($i = 0; $i < strlen($data); ++$i) {
        $text .= $data[$i] ^ $masks[$i % 4];
    }
    return $text;
}

// 加码数据
function enmask($str) {
    $a  = str_split($str, 125);
    $ns = "";
    foreach ($a as $o) {
        $ns .= "\x81" . chr(strlen($o)) . $o;
    }
    return $ns;
}
