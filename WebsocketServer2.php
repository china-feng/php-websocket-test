<?php
// 需装sockets 扩展；windows linux 平台均可
class WebsocketServer
{
    private $address = '0.0.0.0';
    private $port = '8000';
    // private $timeout = 60;
    // private $host="tcp://0.0.0.0:8000";
    private $_sockets;  //主socket
    public $clients;
    
    private $sda  = array(); //已接收的数据
    private $slen = array(); //数据总长度
    private $sjen = array(); //接收数据的长度
    private $ar   = array(); //加密key
    private $n    = array();

    private $onConnect;
    private $onMessage;
    private $onClose;

    public function __construct($address = '', $port='')
    {
            if(!empty($address)){
                $this->address = $address;
            }
            if(!empty($port)) {
                $this->port = $port;
            }
    }
    
    public function on($event, \Closure $method){
        try {
            if (!in_array($event, array('onConnect', 'onMessage', 'onClose'))) {
                throw new Exception("{$event} event fail");
            }
            $this->{$event} = $method;
        } catch (\Exception $e){
             $this->wlog($e->getMessage(), 'exception');
        }
    }

    public function onConnect($client_id){
        if (empty($this->onConnect)) {
            echo  "onConnect 事件未定义 \r\n";
            return;
        }
        $method = $this->onConnect;
        $method($this, $client_id);
    }
    
    public function onMessage($client_id, $msg){
        if (empty($this->onMessage)) {
            echo  "onMessage 事件未定义 \r\n";
            return;
        }
        $method = $this->onMessage;
        $method($this, $client_id, $msg); 
    }
    
    //客服端关闭事件
    public function onClose($client_id){
        if (empty($this->onClose)) {
            echo  "onClose 事件未定义 \r\n";
            return;
        }
        $method = $this->onClose;
        $method($this, $client_id); 
    }
    
    //主服务
    public function service(){
        try {
            //获取tcp协议号码。
            // $tcp = getprotobyname("tcp");
            $this->_sockets = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if(!$this->_sockets)
            {
                throw new Exception("failed to create socket: ".socket_strerror($sock)."\n");
            }
            socket_set_option($this->_sockets, SOL_SOCKET, SO_REUSEADDR, 1);//1表示接受所有的数据包
            socket_bind($this->_sockets, $this->address, $this->port);
            socket_listen($this->_sockets);

        } catch (\Exception $e){
             $this->wlog($e->getMessage(), 'exception');
        }
         echo "listen on $this->address , port:$this->port ... \n";
    }
 
    //服务运行
    public function run(){
            $this->service();
            $this->clients = array($this->_sockets);
            while (true){
                try {
                    $changes = $this->clients;
                    $write = null;
                    $except = null;
                    socket_select($changes,  $write,  $except, NULL);
                    foreach ($changes as $key => $_sock){
                        if($this->_sockets === $_sock){ //判断是不是新接入的socket
                            $socket = socket_accept($this->_sockets);
                            if (empty($socket)) {
                                throw new Exception("stream_socket_accept fail ");
                            }
                            $client_id = md5(uniqid() . mt_rand(100,999));
                            // var_dump($client_id);
                            $this->clients[$client_id] = $socket;
                            // $buffer = $this->receive($client_id);
                            // socket_recv($socket,$buf,1000,0);
                            $this->performHandshake($client_id);
                            $this->onConnect($client_id);
                        } else {
                            $client_id = array_search($_sock, $this->clients);
                            // var_dump(1);
                            // var_dump($client_id);
                            $res = $this->receive($client_id);                            
                            if(strlen($res) < 9) { //客户端主动关闭
                                $this->close($client_id);
                            } else { //客户端信息到来
                                $this->onMessage($client_id, $res);
                            }
                        }
                    }
                } catch (\Exception $e) {
                     $this->wlog($e->getMessage(), 'exception');
                }
            }
        
    }

    //获取客户端数据
    protected function receive($client_id)
    {
        $buffer = '';
        //读取该socket的信息，注意：第二个参数是引用传参即接收数据，第三个参数是接收数据的长度
        do {
            $l = socket_recv($this->clients[$client_id], $buf, 1000, 0);
            $buffer .= $buf;
        } while ($l == 1000);
        return $this->uncode($buffer, $client_id);
    }

    //握手操作
    protected function performHandshake($client_id)
    {   
        $buffer = $this->receive($client_id);
        
        $buf  = substr($buffer,strpos($buffer,'Sec-WebSocket-Key:')+18);
        $key  = trim(substr($buf,0,strpos($buf,"\r\n")));
        $new_key = base64_encode(sha1($key."258EAFA5-E914-47DA-95CA-C5AB0DC85B11",true));
         
        //按照协议组合信息进行返回
        $new_message = "HTTP/1.1 101 Switching Protocols\r\n";
        $new_message .= "Upgrade: websocket\r\n";
        $new_message .= "Sec-WebSocket-Version: 13\r\n";
        $new_message .= "Connection: Upgrade\r\n";
        $new_message .= "Sec-WebSocket-Accept: " . $new_key . "\r\n\r\n";
        socket_write($this->clients[$client_id], $new_message, strlen($new_message));

        return true;
    }
    
    /**
     * 发送数据
     * @param $newClient 新接入的socket
     * @param $msg   要发送的数据
     * @return int|string
     */
    public function send($client_id, $info)
    {
        $str = $this->code($info);
        socket_write($this->clients[$client_id], $str, strlen($str));
    }

    //解码函数
    protected function uncode($str,$key){
        $mask = array(); 
        $data = ''; 
        $msg = unpack('H*',$str);
        $head = substr($msg[1],0,2); 
        $n = 0;
        $len = 0;
        if ($head == '81' && !isset($this->slen[$key])) { 
            $len=substr($msg[1],2,2);
            $len=hexdec($len);//把十六进制的转换为十进制
            if(substr($msg[1],2,2)=='fe'){
                $len=substr($msg[1],4,4);
                $len=hexdec($len);
                $msg[1]=substr($msg[1],4);
            }else if(substr($msg[1],2,2)=='ff'){
                $len=substr($msg[1],4,16);
                $len=hexdec($len);
                $msg[1]=substr($msg[1],16);
            }
            $mask[] = hexdec(substr($msg[1],4,2)); 
            $mask[] = hexdec(substr($msg[1],6,2)); 
            $mask[] = hexdec(substr($msg[1],8,2)); 
            $mask[] = hexdec(substr($msg[1],10,2));
            $s = 12;
            $n=0;
        }else if(!empty($this->slen[$key]) && $this->slen[$key] > 0){
            $len=$this->slen[$key];
            $mask=$this->ar[$key];
            $n=$this->n[$key];
            $s = 0;
        }
         
        $e = strlen($msg[1])-2;
        for ($i=0; $i<= $e; $i+= 2) { 
            @$data .= chr($mask[$n%4]^hexdec(substr($msg[1],$i,2))); 
            $n++; 
        } 
        $dlen=strlen($data);
         
        if($len > 255 && $len > $dlen+intval($this->sjen[$key])){
            $this->ar[$key]=$mask;
            $this->slen[$key]=$len;
            $this->sjen[$key]=$dlen+intval($this->sjen[$key]);
            $this->sda[$key]=$this->sda[$key].$data;
            $this->n[$key]=$n;
            return false;
        }else{
            unset($this->ar[$key],$this->slen[$key],$this->sjen[$key],$this->n[$key]);
            @$data=$this->sda[$key].$data;
            unset($this->sda[$key]);
            return $data;
        }
         
    }

    //与uncode相对
    protected function code($msg){
        $frame = array(); 
        $frame[0] = '81'; 
        $len = strlen($msg);
        if($len < 126){
            $frame[1] = $len<16?'0'.dechex($len):dechex($len);
        }else if($len < 65025){
            $s=dechex($len);
            $frame[1]='7e'.str_repeat('0',4-strlen($s)).$s;
        }else{
            $s=dechex($len);
            $frame[1]='7f'.str_repeat('0',16-strlen($s)).$s;
        }
        $frame[2] = $this->ord_hex($msg);
        $data = implode('',$frame); 
        return pack("H*", $data); //按格式打包成二进制string
    }
     
    protected function ord_hex($data) { 
        $msg = ''; 
        $l = strlen($data); 
        for ($i= 0; $i<$l; $i++) { 
            $msg .= dechex(ord($data{$i})); 
        } 
        return $msg; 
    }

    /**
     * 关闭socket
     */
    public function close($client_id){
        // stream_socket_shutdown($this->clients[$client_id],STREAM_SHUT_RDWR);
        socket_close($this->clients[$client_id]);
        $this->onClose($client_id);
        unset($this->clients[$client_id]);
    }

    public function throwException($message, $code = 0)
    {
        throw new \Exception($message, $code);
    }

    protected function wlog($log, $dir = ''){
        $dir = __DIR__ . '/logs/' . $dir . '/';
        if (!is_dir($dir)) {
            mkdir($dir, '0777', true);
        }
        $file = $dir . date('Y-m-d') . '.log';
        $log = date('Y-m-d H:i:s') . ' : ' . $log;
        file_put_contents($file, $log . "\r\n", FILE_APPEND);
    }
}
 
// $service = new WebsocketServer('0.0.0.0','8000');

// $service->on('onConnect',function($websocket_server, $client_id){
//     $websocket_server->send($client_id, $client_id);
// });

// $service->on('onMessage',function($websocket_server, $client_id, $msg){
//     $websocket_server->send($client_id, $msg);
// });

// $service->on('onClose',function($websocket_server, $client_id){
//     //关闭之后不能经行通信
//     // $websocket_server->send($client_id, '您主动关闭了连接' . $client_id);
// });

// $service->run();