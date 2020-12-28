<?php
/**
 * 不需要额外安装扩展；但是此类在windows 执行会有问题，linux无问题
 */
class WebsocketServer
{
    private $address = '0.0.0.0';
    private $port = '8000';
    // private $timeout = 60;
    // private $host="tcp://0.0.0.0:8000";
    private $_sockets;  //主socket
    public $clients;
    
    private $onConnect;
    private $onMessage;
    private $onClose;

    protected static $opcodes = array( //允许发送数据类型
        'continuation' => 0,
        'text'         => 1,
        'binary'       => 2,
        'close'        => 8,
        'ping'         => 9,
        'pong'         => 10,
    );

    protected $options = [
      'timeout'       => 60,
      'fragment_size' => 4096,
      'port'          => 8000,
    ];

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
            $tcp = getprotobyname("tcp");
            $this->_sockets = stream_socket_server('tcp://' . $this->address . ':' . $this->port, $errno, $errstr);;
            
            if(!$this->_sockets)
            {
                throw new Exception("failed to create socket: ".socket_strerror($sock)."\n");
            }
            stream_set_blocking($this->_sockets,0); //在流上设置阻止/非阻止模式
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
                    @stream_select($changes,  $write,  $except, NULL);
                    foreach ($changes as $key => $_sock){
                        if($this->_sockets === $_sock){ //判断是不是新接入的socket
                            if (!empty($this->options['timeout'])) {
                                $socket = @stream_socket_accept($this->_sockets, $this->options['timeout']);
                            } else {
                                $socket = @stream_socket_accept($this->_sockets);
                            }
                            if (empty($socket)) {
                                throw new Exception("stream_socket_accept fail ");
                            }
                            $client_id= md5(uniqid() . mt_rand(100,999));
                            $this->clients[$client_id] = $socket;
                            $this->performHandshake($client_id);
                            $this->onConnect($client_id);
                        } else {
                            $client_id = array_search($_sock, $this->clients);
                            $res = $this->receive($client_id);

                            
                            if(strlen($res) < 9) { //客户端主动关闭
                                $this->close($client_id);
                            }else{ //客户端信息到来
                                $this->onMessage($client_id, $res);
                            }
                            
                        }
                    }
                } catch (\Exception $e){
                     $this->wlog($e->getMessage(), 'exception');
                }
            }
        
    }

    //获取客户端数据
    protected function receive($client_id)
    {
        $payload = '';
        do {
            $response = $this->receiveFragment($client_id);
            $payload .= $response[0];
            // var_dump($response);
        } while (!$response[1]);

        return $payload;
    }

    protected function receiveFragment($client_id)
    {
        // Just read the main fragment information first.
        $data = $this->read(2, $client_id);

        // Is this the final fragment?  // Bit 0 in byte 0
        $final = (bool) (ord($data[0]) & 1 << 7);

        // Should be unused, and must be false…  // Bits 1, 2, & 3
        $rsv1  = (bool) (ord($data[0]) & 1 << 6);
        $rsv2  = (bool) (ord($data[0]) & 1 << 5);
        $rsv3  = (bool) (ord($data[0]) & 1 << 4);

        // Parse opcode
        $opcode_int = ord($data[0]) & 31; // Bits 4-7
        $opcode_ints = array_flip(self::$opcodes);
        if (!array_key_exists($opcode_int, $opcode_ints)) {
            // throw new ConnectionException(
                // "Bad opcode in websocket frame: $opcode_int",
                // ConnectionException::BAD_OPCODE
            // );
            $this->throwException("Bad opcode in websocket frame: $opcode_int");
        }
        $opcode = $opcode_ints[$opcode_int];

        // Record the opcode if we are not receiving a continutation fragment
        // if ($opcode !== 'continuation') {
        //     $this->last_opcode = $opcode;
        // }

        // Masking?
        $mask = (bool) (ord($data[1]) >> 7);  // Bit 0 in byte 1

        $payload = '';

        // Payload length
        $payload_length = (int) ord($data[1]) & 127; // Bits 1-7 in byte 1
        if ($payload_length > 125) {
            if ($payload_length === 126) {
                $data = $this->read(2, $client_id); // 126: Payload is a 16-bit unsigned int
            } else {
                $data = $this->read(8, $client_id); // 127: Payload is a 64-bit unsigned int
            }
            $payload_length = bindec(self::sprintB($data));
        }

        // Get masking key.
        if ($mask) {
            $masking_key = $this->read(4, $client_id);
        }

        // Get the actual payload, if any (might not be for e.g. close frames.
        if ($payload_length > 0) {
            $data = $this->read($payload_length, $client_id);

            if ($mask) {
                // Unmask payload.
                for ($i = 0; $i < $payload_length; $i++) {
                    $payload .= ($data[$i] ^ $masking_key[$i % 4]);
                }
            } else {
                $payload = $data;
            }
        }

        // if we received a ping, send a pong
        if ($opcode === 'ping') {
            $this->send($client_id, $payload, 'pong', true);
        }

        if ($opcode === 'close') {
            // Get the close status.
            if ($payload_length > 0) {
                $status_bin = $payload[0] . $payload[1];
                $status = bindec(sprintf("%08b%08b", ord($payload[0]), ord($payload[1])));
                // $this->close_status = $status;
            }
            // Get additional close message-
            if ($payload_length >= 2) {
                $payload = substr($payload, 2);
            }

            // if ($this->is_closing) {
            //     $this->is_closing = false; // A close response, all done.
            // } else {
                $this->send($client_id, $status_bin . 'Close acknowledged: ' . $status, 'close', true); // Respond.
            // }

            // Close the socket.
            fclose($this->clients[$client_id]);

            // Closing should not return message.
            return [null, true];
        }

        return [$payload, $final];
    }


    //握手操作
    protected function performHandshake($client_id)
    {   //var_dump($socket);
        $request = '';
        do {
            $buffer = stream_get_line($this->clients[$client_id], 1024, "\r\n");
            $request .= $buffer . "\n";
            $metadata = stream_get_meta_data($this->clients[$client_id]);
        } while (!feof($this->clients[$client_id]) && $metadata['unread_bytes'] > 0);

        if (!preg_match('/GET (.*) HTTP\//mUi', $request, $matches)) {
            $this->throwException("No GET in request:\n" . $request);
        }
        $get_uri = trim($matches[1]);
        $uri_parts = parse_url($get_uri);

        $this->request = explode("\n", $request);
        $this->request_path = $uri_parts['path'];
        /// @todo Get query and fragment as well.

        if (!preg_match('#Sec-WebSocket-Key:\s(.*)$#mUi', $request, $matches)) {
            // throw new Exception("Client had no Key in upgrade request:\n" . $request);
            $this->throwException("Client had no Key in upgrade request:\n" . $request);
        }

        $key = trim($matches[1]);

        /// @todo Validate key length and base 64...
        $response_key = base64_encode(pack('H*', sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));

        $header = "HTTP/1.1 101 Switching Protocols\r\n"
                . "Upgrade: websocket\r\n"
                . "Connection: Upgrade\r\n"
                . "Sec-WebSocket-Accept: $response_key\r\n"
                . "\r\n";

        $this->write($header, $client_id);
    }

    protected function write($data, $client_id)
    {
        $written = fwrite($this->clients[$client_id], $data);
        if ($written === false) {
            $length = strlen($data);
            $this->throwException("Failed to write $length bytes.");
        }

        if ($written < strlen($data)) {
            $length = strlen($data);
            $this->throwException("Could only write $written out of $length bytes.");
        }
    }

    protected function read($length, $client_id)
    {
        $data = '';
        while (strlen($data) < $length) {
            $buffer = fread($this->clients[$client_id], $length - strlen($data));
            if ($buffer === false) {
                $read = strlen($data);
                $this->throwException("Broken frame, read $read of stated $length bytes.");
            }
            if ($buffer === '') {
                $this->throwException("Empty read; connection dead?");
            }
            $data .= $buffer;
        }
        return $data;
    }

    
    /**
     * 发送数据
     * @param $newClient 新接入的socket
     * @param $msg   要发送的数据
     * @return int|string
     */
    public function send($client_id, $payload, $opcode = 'text', $masked = false)
    {

        if (!$payload) {
            return false;
        }
        if (!in_array($opcode, array_keys(self::$opcodes))) {
            $this->throwException("Bad opcode '$opcode'.  Try 'text' or 'binary'.");
        }

        $payload_chunks = str_split($payload, $this->options['fragment_size']);

        for ($index = 0; $index < count($payload_chunks); ++$index) {
            $chunk = $payload_chunks[$index];
            $final = $index == count($payload_chunks) - 1;

            $this->sendFragment($client_id, $final, $chunk, $opcode, $masked);

            // all fragments after the first will be marked a continuation
            $opcode = 'continuation';
        }
    }

    protected function sendFragment($client_id, $final, $payload, $opcode, $masked)
    {
        // Binary string for header.
        $frame_head_binstr = '';

        // Write FIN, final fragment bit.
        $frame_head_binstr .= (bool) $final ? '1' : '0';

        // RSV 1, 2, & 3 false and unused.
        $frame_head_binstr .= '000';

        // Opcode rest of the byte.
        $frame_head_binstr .= sprintf('%04b', self::$opcodes[$opcode]);

        // Use masking?
        $frame_head_binstr .= $masked ? '1' : '0';

        // 7 bits of payload length...
        $payload_length = strlen($payload);
        if ($payload_length > 65535) {
            $frame_head_binstr .= decbin(127);
            $frame_head_binstr .= sprintf('%064b', $payload_length);
        } elseif ($payload_length > 125) {
            $frame_head_binstr .= decbin(126);
            $frame_head_binstr .= sprintf('%016b', $payload_length);
        } else {
            $frame_head_binstr .= sprintf('%07b', $payload_length);
        }

        $frame = '';

        // Write frame head to frame.
        foreach (str_split($frame_head_binstr, 8) as $binstr) {
            $frame .= chr(bindec($binstr));
        }

        // Handle masking
        if ($masked) {
            // generate a random mask:
            $mask = '';
            for ($i = 0; $i < 4; $i++) {
                $mask .= chr(rand(0, 255));
            }
            $frame .= $mask;
        }

        // Append payload to frame:
        for ($i = 0; $i < $payload_length; $i++) {
            $frame .= ($masked === true) ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];
        }

        $this->write($frame, $client_id);
    }

    /**
     * 关闭socket
     */
    public function close($client_id){
        stream_socket_shutdown($this->clients[$client_id],STREAM_SHUT_RDWR);
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
//     $websocket_server->send($client_id, '完成了连接：{$client_id}');
// });

// $service->on('onMessage',function($websocket_server, $client_id, $msg){
//     $websocket_server->send($client_id, '服务器看到了您发过来的信息:' . $msg);
// });

// $service->on('onClose',function($websocket_server, $client_id){
//     //关闭之后不能经行通信
//     // $websocket_server->send($client_id, '您主动关闭了连接' . $client_id);
// });

// $service->run();