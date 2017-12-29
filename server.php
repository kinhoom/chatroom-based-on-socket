<?php
error_reporting(E_ALL);
set_time_limit(0);
date_default_timezone_set('Asia/shanghai');
class WebSocket{
	const LISTEN_SOCKET_NUM = 9;
	private $sockets=array();
	private $sks=array();
	private $master;
	public function __construct($host,$port){
		try{
		    $this->master = socket_create(AF_INET, SOCK_STREAM, SOL_TCP) or die('socket create error!');
		    socket_set_option($this->master, SOL_SOCKET, SO_REUSEADDR, 1) or die('reuse addr error!');
		    if(!socket_bind($this->master, $host, $port)) {
			 throw new Exception(socket_strerror(socket_last_error()));
		    }	
		    socket_listen($this->master, self::LISTEN_SOCKET_NUM) or die('socket listen error!');
		}catch(Exception $e){
		    $error_code=socket_last_error();
		    $error_msg=$e->getMessage();
		    $this->error(array('error_ini_server',$error_code,$error_msg));
		}
		$this->sockets[0]=array('resource'=>$this->master);
		//$pid=posix_getpid();
		//$this->debug(array("server: {$this->master} started,pid: {$pid}"));
		while(true){
		     try{
			  $this->doServer();	
		     }catch(Exception $e){

		     }
		}
	}
	private function doServer(){
	     $write=$except=null;
	     //var_dump($this->sockets);
		 $index=array_keys($this->sockets);
		 //var_dump($index);
	     for($i=0;$i<count($this->sockets);$i++){
	     	 $in=$index[$i];
	    	 $sks[]=$this->sockets[$in]['resource'];
	 	 }
	     //var_dump($sks);
	    //var_dump($sks);
	    //var_dump($sks);
	     $read_num=socket_select($sks,$write,$except,null);	
	    // var_dump($sks);
	//var_dump($this->sks);
	    if(false===$read_num){
			$this->error(array('error_select',$error_code=socket_last_error(),socket_strerror($error_code)));
			return;
	    }
	    foreach($sks as $sk){
	     	var_dump($sk.'------'.$this->master);
			if($sk==$this->master){
			     $client = socket_accept($this->master);
			    var_dump($client);
				if (false === $client) {
		            $this->error(array(
		                'err_accept',
		                $err_code = socket_last_error(),
		                socket_strerror($err_code)
		            ));
		            continue;
		        } else {
		            self::connect($client);
		            continue;
		        }
			}else{
				$bytes = @socket_recv($sk, $buffer, 2048, 0);
				var_dump($bytes.'---bytes');
				if($bytes<9){
					//var_dump($bytes);
					//var_dump((int)$sk);
					$recv_msg=$this->disconnect($sk);
				}else{
					if(!$this->sockets[(int)$sk]['handshake']){
						self::handShake($sk,$buffer);
						continue;
					}else{
						$recv_msg = self::parse($buffer);
					}
				}
				if(is_array($recv_msg)){
					array_unshift($recv_msg, 'receive_msg');
					//var_dump($recv_msg);
					$msg=self::dealMsg($sk,$recv_msg);
					$this->broadcast($msg);
				}
				
			}
		}
	}
	private function broadcast($data) {
	    foreach ($this->sockets as $socket) {
	        if ($socket['resource'] == $this->master) {
	            continue;
	        }
	        socket_write($socket['resource'], $data, strlen($data));
	    }
	}
	private function dealMsg($socket, $recv_msg) {
	    $msg_type = $recv_msg['type'];
	    $msg_content = $recv_msg['content'];
	    $response = array();
	    switch ($msg_type) {
	        case 'login':
	            $this->sockets[(int)$socket]['uname'] = $msg_content;
	            // 取得最新的名字记录
	            $user_list=array();
	            //var_Dump($this->sockets);
	            $index=array_keys($this->sockets);
	            for($i=0;$i<count($this->sockets);$i++){
	     	 		$in=$index[$i];
	     	 		if(array_key_exists('uname', $this->sockets[$in]))
	    	 			$user_list[]=$this->sockets[$in]['uname'];
	 	 		}
	            // $user_list = array_column($this->sockets, 'uname');
	            $response['type'] = 'login';
	            $response['content'] = $msg_content;
	            var_dump($user_list);
	            $response['user_list'] = $user_list;
	            break;
	        case 'logout':
	        	$index=array_keys($this->sockets);
	            for($i=0;$i<count($this->sockets);$i++){
	     	 		$in=$index[$i];
	     	 		if(array_key_exists('uname', $this->sockets[$in]))
	    	 			$user_list[]=$this->sockets[$in]['uname'];
	 	 		}        	
	            //$user_list = array_column($this->sockets, 'uname');
	            $response['type'] = 'logout';
	            $response['content'] = $msg_content;
	            $response['user_list'] = $user_list;
	            break;
	        case 'user':
	            $uname = $this->sockets[(int)$socket]['uname'];
	            $response['type'] = 'user';
	            $response['from'] = $uname;
	            $response['content'] = $msg_content;
	            break;
	    }
	    //var_dump($this->build(json_encode($response)).'secondsend!!!');
	    return $this->build(json_encode($response));
	}
	private function parse($buffer) {
	        $decoded = '';
	        $len = ord($buffer[1]) & 127;
	        if ($len === 126) {
	            $masks = substr($buffer, 4, 4);
	            $data = substr($buffer, 8);
	        } else if ($len === 127) {
	            $masks = substr($buffer, 10, 4);
	            $data = substr($buffer, 14);
	        } else {
	            $masks = substr($buffer, 2, 4);
	            $data = substr($buffer, 6);
	        }
	        for ($index = 0; $index < strlen($data); $index++) {
	            $decoded .= $data[$index] ^ $masks[$index % 4];
	        }
	        var_dump(json_decode($decoded, true).'kinhoom');
	        return json_decode($decoded, true);
	}
	private function handShake($sk,$buffer){
		$line=substr($buffer,strpos($buffer,'Sec-WebSocket-Key:')+18);
		$key=trim(substr($line,0,strpos($line, "\r\n")));
		$upgrade_key = base64_encode(sha1($key . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));
		$upgrade_message  = "HTTP/1.1 101 Switching Protocols\r\n";
		$upgrade_message .= "Upgrade: websocket\r\n";
		$upgrade_message .= "Sec-WebSocket-Version: 13\r\n";
		$upgrade_message .= "Connection: Upgrade\r\n";
		$upgrade_message .= "Sec-WebSocket-Accept:" . $upgrade_key . "\r\n\r\n";
		socket_write($sk, $upgrade_message, strlen($upgrade_message));
		$this->sockets[(int)$sk]['handshake'] = true;
		socket_getpeername($sk, $ip,$port);
		/*
		$this->debug(array(
	            'hand_shake',
	            $sk,
	            $ip,
	            $port
	        	));
	    */
		$msg = array(
	        'type' => 'handshake',
	        'content' => 'done',
		);
		//echo json_encode($msg);       //{"type":"handshake","content":"done"}
		$msg=$this->build(json_encode($msg));
		socket_write($sk, $msg, strlen($msg));
	}
	private function build($msg){
	 		$frame = array();
	        $frame[0] = '81';
	        $len = strlen($msg);
	        if ($len < 126) {
	            $frame[1] = $len < 16 ? '0' . dechex($len) : dechex($len);
	        } else if ($len < 65025) {
	            $s = dechex($len);
	            $frame[1] = '7e' . str_repeat('0', 4 - strlen($s)) . $s;
	        } else {
	            $s = dechex($len);
	            $frame[1] = '7f' . str_repeat('0', 16 - strlen($s)) . $s;
	        }
	        $data = '';
	        $l = strlen($msg);
	        for ($i = 0; $i < $l; $i++) {
	            $data .= dechex(ord($msg{$i}));
	        }
	        $frame[2] = $data;
	        $data = implode('', $frame);
	        return pack("H*", $data);
	}
	private function disconnect($socket){
		$recv_msg=array('type'=>'logout','content'=>$this->sockets[(int)$socket]['uname']);
		unset($this->sockets[(int)$socket]);
		var_dump($this->sockets);
		return $recv_msg;
		//var_dump($socket);
	}
	private function connect($socket){
	     socket_getpeername($socket, $ip, $port);
	     $socket_info=array(
		    	'resource' => $socket,
	            'uname' => '',
	            'handshake' => false,
	            'ip' => $ip,
	            'port' => $port,
	     );
	    $this->sockets[(int)$socket]=$socket_info;
	     //var_dump($this->sockets);
	}
	private function debug(Array $info){
	     $time=date('Y-m-d H:i:s');
	     array_unshift($info,$time);
	     $info=array_map('json_encode',$info);
	     file_put_contents('./websocket_debug.log', implode(' | ', $info) . "\r\n", FILE_APPEND);
	}
	private function error(Array $info){
	     $time=date('Y-m-d H:i:s');
	     array_unshift($info, $time);
	     var_dump($info);
	     $info=array_map('json_encode',$info);
	     file_put_contents('./websocket_error.log', implode(' | ', $info) . "\r\n", FILE_APPEND);	
	}
}
$ws = new WebSocket("192.168.6.107", "8089");
