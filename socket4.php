<?php

class SocketService
{
    private $address;
    private $port;
    private $timeout = 60;
    public $host="tcp://0.0.0.0:8000";
    private $_sockets;
    public $clients;
    public $maxid=1000;
    
    private $request = '';
    private $request_path = '';
    protected static $opcodes = array(
        'continuation' => 0,
        'text'         => 1,
        'binary'       => 2,
        'close'        => 8,
        'ping'         => 9,
        'pong'         => 10,
    );

    public function __construct($address = '', $port='')
    {
            if(!empty($address)){
                $this->address = $address;
            }
            if(!empty($port)) {
                $this->port = $port;
            }
    }
    
    public function onConnect($client_id){
        echo  "Client client_id:{$client_id}   \n";
         
    }
    
    public function onMessage($client_id,$msg){
        //发给所有的
        foreach($this->clients as $kk=>$cc){
            if($kk>0){
                $this->send($cc, $msg);
            }                                
        }    
    }
    
    public function onClose($client_id){
        echo "$client_id close \n";
    }
    
    public function service(){
        //获取tcp协议号码。
        $tcp = getprotobyname("tcp");
        $sock = stream_socket_server($this->host, $errno, $errstr);;
        
        if(!$sock)
        {
            throw new Exception("failed to create socket: ".socket_strerror($sock)."\n");
        }
        stream_set_blocking($sock,0);
        $this->_sockets = $sock;
         echo "listen on $this->address $this->host ... \n";
    }
 
    public function run(){
        $this->service();
        $this->clients[] = $this->_sockets;
        while (true){
            $changes = $this->clients;
            $write = NULL;
            $except = NULL;
            @stream_select($changes,  $write,  $except, NULL);
            foreach ($changes as $key => $_sock){
                var_dump($key);
                if($this->_sockets === $_sock){ //判断是不是新接入的socket
                    // if(($newClient = @stream_socket_accept($_sock))  === false){
                    //     unset($this->clients[$key]);
                    //     continue;
                    // }
                    // $line = trim(stream_socket_recvfrom($newClient, 1024));
                    $socket = $this->connect();

                    //握手
                    // $this->handshaking($newClient);
                    $this->maxid++;
                    $this->clients[$this->maxid] = $socket;
                    $this->onConnect($this->maxid);
                } else {
                    // $res=@stream_socket_recvfrom($_sock,  2048);
                    $res = $this->receive($_sock);
                    // var_dump($res);
                    //客户端主动关闭
                    if(strlen($res) < 9) {
                        stream_socket_shutdown($this->clients[$key],STREAM_SHUT_RDWR);
                        unset($this->clients[$key]);
                        $this->onClose($key);
                    }else{
                        //解密
                        // $msg = $this->decode($res);
                        // $this->onMessage($key,$res);
                        $this->send($_sock, $msg);
                    }
                     
                    
                }
            }
        }
    }
 
    //socket 是否被连接
    public function isConnected($socket)
    {
        return $socket && get_resource_type($socket) == 'stream';
    }

    //获取客户端数据
    public function receive($socket)
    {
        $payload = '';
        do {
            $response = $this->receiveFragment($socket);
            $payload .= $response[0];
            // var_dump($response);
        } while (!$response[1]);

        return $payload;
    }

    protected function receiveFragment($socket)
    {
        // Just read the main fragment information first.
        $data = $this->read(2, $socket);

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
        }
        $opcode = $opcode_ints[$opcode_int];

        // Record the opcode if we are not receiving a continutation fragment
        if ($opcode !== 'continuation') {
            $this->last_opcode = $opcode;
        }

        // Masking?
        $mask = (bool) (ord($data[1]) >> 7);  // Bit 0 in byte 1

        $payload = '';

        // Payload length
        $payload_length = (int) ord($data[1]) & 127; // Bits 1-7 in byte 1
        if ($payload_length > 125) {
            if ($payload_length === 126) {
                $data = $this->read(2, $socket); // 126: Payload is a 16-bit unsigned int
            } else {
                $data = $this->read(8, $socket); // 127: Payload is a 64-bit unsigned int
            }
            $payload_length = bindec(self::sprintB($data));
        }

        // Get masking key.
        if ($mask) {
            $masking_key = $this->read(4, $socket);
        }

        // Get the actual payload, if any (might not be for e.g. close frames.
        if ($payload_length > 0) {
            $data = $this->read($payload_length, $socket);

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
            $this->send($payload, 'pong', true);
        }

        if ($opcode === 'close') {
            // Get the close status.
            if ($payload_length > 0) {
                $status_bin = $payload[0] . $payload[1];
                $status = bindec(sprintf("%08b%08b", ord($payload[0]), ord($payload[1])));
                $this->close_status = $status;
            }
            // Get additional close message-
            if ($payload_length >= 2) {
                $payload = substr($payload, 2);
            }

            if ($this->is_closing) {
                $this->is_closing = false; // A close response, all done.
            } else {
                $this->send($status_bin . 'Close acknowledged: ' . $status, 'close', true); // Respond.
            }

            // Close the socket.
            fclose($socket);

            // Closing should not return message.
            return [null, true];
        }

        return [$payload, $final];
    }

    //与客户端建立连接
    protected function connect()
    {
        if (empty($this->timeout)) {
            $socket = @stream_socket_accept($this->_sockets);
            if (! $socket) {
                // throw new Exception('Server failed to connect1.');
            }
        } else {
            $socket = @stream_socket_accept($this->_sockets, $this->timeout);
            if (!$socket) {
                // throw new Exception('Server failed to connect2.');
            }
            stream_set_timeout($socket, $this->timeout);
        }

        $this->performHandshake($socket);
        return $socket;
    }

    //握手操作
    protected function performHandshake($socket)
    {   //var_dump($socket);
        $request = '';
        do {
            $buffer = stream_get_line($socket, 1024, "\r\n");
            $request .= $buffer . "\n";
            $metadata = stream_get_meta_data($socket);
        } while (!feof($socket) && $metadata['unread_bytes'] > 0);

        if (!preg_match('/GET (.*) HTTP\//mUi', $request, $matches)) {
            // throw new Exception("No GET in request:\n" . $request);
        }
        $get_uri = trim($matches[1]);
        $uri_parts = parse_url($get_uri);

        $this->request = explode("\n", $request);
        $this->request_path = $uri_parts['path'];
        /// @todo Get query and fragment as well.

        if (!preg_match('#Sec-WebSocket-Key:\s(.*)$#mUi', $request, $matches)) {
            // throw new Exception("Client had no Key in upgrade request:\n" . $request);
        }

        $key = trim($matches[1]);

        /// @todo Validate key length and base 64...
        $response_key = base64_encode(pack('H*', sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));

        $header = "HTTP/1.1 101 Switching Protocols\r\n"
                . "Upgrade: websocket\r\n"
                . "Connection: Upgrade\r\n"
                . "Sec-WebSocket-Accept: $response_key\r\n"
                . "\r\n";

        $this->write($header, $socket);
    }

    protected function write($data, $socket)
    {
        $written = fwrite($socket, $data);
        // var_dump($written);
        if ($written === false) {
            $length = strlen($data);
            // var_dump(1);
            // $this->throwException("Failed to write $length bytes.");
        }

        if ($written < strlen($data)) {
            $length = strlen($data);
            // var_dump(2);
            // $this->throwException("Could only write $written out of $length bytes.");
        }
    }

    protected function read($length, $socket)
    {
        $data = '';
        while (strlen($data) < $length) {
            $buffer = fread($socket, $length - strlen($data));
            if ($buffer === false) {
                $read = strlen($data);
                // $this->throwException("Broken frame, read $read of stated $length bytes.");
            }
            if ($buffer === '') {
                // $this->throwException("Empty read; connection dead?");
            }
            $data .= $buffer;
        }
        return $data;
    }

    /**
     * 握手处理
     * @param $newClient socket
     * @return int  接收到的信息
     */
    public function handshaking($newClient){
        // var_dump($newClient);
        $request = '';
        do {
            $buffer = stream_get_line($newClient, 1024, "\r\n");
            $request .= $buffer . "\n";
            $metadata = stream_get_meta_data($newClient);
        } while (!feof($newClient) && $metadata['unread_bytes'] > 0);

        if (!preg_match('/GET (.*) HTTP\//mUi', $request, $matches)) {
            // throw new ConnectionException("No GET in request:\n" . $request);
        }
        $get_uri = trim($matches[1]);
        $uri_parts = parse_url($get_uri);

         if (!preg_match('#Sec-WebSocket-Key:\s(.*)$#mUi', $request, $matches)) {
            // throw new Exception("Client had no Key in upgrade request:\n" . $request);
        }

        $key = trim($matches[1]);
        // var_dump($key);
        $secAccept = base64_encode(pack('H*', sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
        $upgrade  = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
            "Upgrade: websocket\r\n" .
            "Connection: Upgrade\r\n" .
            "WebSocket-Origin: {$this->address}\r\n" .
            "WebSocket-Location: ws://{$this->address}:{$this->port}/websocket/websocket\r\n".
            "Sec-WebSocket-Accept:{$secAccept}\r\n\r\n";
        return stream_socket_sendto($newClient, $upgrade);
    }
    
    
    
    
    /**
     * 发送数据
     * @param $newClient 新接入的socket
     * @param $msg   要发送的数据
     * @return int|string
     */
    // public function send($newClient, $msg){
    //     $msg = $this->encode($msg);
    //     stream_socket_sendto($newClient, $msg);
    // }
    public function send($socket, $payload, $opcode = 'text', $masked = true)
    {
        // if (!$this->isConnected()) {
        //     $this->connect();
        // }

        if (!in_array($opcode, array_keys(self::$opcodes))) {
            // throw new BadOpcodeException("Bad opcode '$opcode'.  Try 'text' or 'binary'.");
        }

        $payload_chunks = str_split($payload, $this->options['fragment_size']);

        for ($index = 0; $index < count($payload_chunks); ++$index) {
            $chunk = $payload_chunks[$index];
            $final = $index == count($payload_chunks) - 1;

            $this->sendFragment($socket, $final, $chunk, $opcode, $masked);

            // all fragments after the first will be marked a continuation
            $opcode = 'continuation';
        }
    }

    protected function sendFragment($socket, $final, $payload, $opcode, $masked)
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

        $this->write($frame, $socket);
    }

    /**
     * 解析接收数据
     * @param $buffer
     * @return null|string
     */
    public function decode($buffer){
        $len = $masks = $data = $decoded = null;
        $len = ord($buffer[1]) & 127;
        if ($len === 126)  {
            $masks = substr($buffer, 4, 4);
            $data = substr($buffer, 8);
        } else if ($len === 127)  {
            $masks = substr($buffer, 10, 4);
            $data = substr($buffer, 14);
        } else  {
            $masks = substr($buffer, 2, 4);
            $data = substr($buffer, 6);
        }
        for ($index = 0; $index < strlen($data); $index++) {
            $decoded .= $data[$index] ^ $masks[$index % 4];
        }
        return $decoded;
    }
 
    
    /**
    *打包消息
    **/
     public function encode($buffer) {
        $first_byte="\x81";
        $len=strlen($buffer);
        if ($len <= 125) {
            $encode_buffer = $first_byte . chr($len) . $buffer;
        } else {
            if ($len <= 65535) {
                $encode_buffer = $first_byte . chr(126) . pack("n", $len) . $buffer;
            } else {
                $encode_buffer = $first_byte . chr(127) . pack("xxxxN", $len) . $buffer;
            }
        }
        return $encode_buffer;
    }
 
    /**
     * 关闭socket
     */
    public function close(){
        return socket_close($this->_sockets);
    }
}
 
$sock = new SocketService('0.0.0.0','8000');
$sock->run();