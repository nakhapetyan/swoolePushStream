<?php

/*
 * Swoole push stream implementation
 */

class PushStream
{
	public $server;
	public $channels = [];

	public function __construct($port = 8098)
	{
		$this->server = new swoole_websocket_server('0.0.0.0', $port, SWOOLE_BASE, SWOOLE_SOCK_TCP | SWOOLE_SSL);
		$this->server->set([
			'open_http2_protocol' => true,
			'worker_num' => 1,
			'heartbeat_idle_time' => 300,
			'heartbeat_check_interval' => 60,
			'log_level' => 3,
		]);
		$this->server->on('handshake', [$this, 'onHandshake']);
		$this->server->on('message', [$this, 'onMessage']);
		$this->server->on('close', [$this, 'onClose']);
		$this->server->on('request', [$this, 'onRequest']);
		$this->server->start();
	}

	protected function onMessage($server, $frame)
	{
		$server->push($frame->fd, "server: {$frame->data}");
	}

	protected function onClose($server, $fd)
	{
		foreach ($this->channels as $channel => &$connections) {
			if (!isset($connections['users'][$fd])) continue;
			unset($connections['users'][$fd]);
			$this->sendUsersCount($channel);
		}
	}

	protected function onRequest(swoole_http_request $request, swoole_http_response $response)
	{
		if ($request->server["path_info"] =='/pub'){
			$cnt = push($request->get["id"], $request->rawContent(), 0, $request->get["save"]);
			// {"channel": "my_channel_1", "published_messages": 1, "stored_messages": 1, "subscribers": 0}
			$response->end(json_encode([ 'subscribers' => $cnt, ]));
		} else {
			$this->outClient($request, $response);
		}
	}

	protected function onHandshake(swoole_http_request $request, swoole_http_response $response)
	{
		// websocket handshake version: 13
		if (!isset($request->header['sec-websocket-key'])) {
			//'Bad protocol implementation: it is not RFC6455.'
			$response->end();
			return false;
		}
		if (0 === preg_match('#^[+/0-9A-Za-z]{21}[AQgw]==$#', $request->header['sec-websocket-key'])
			|| 16 !== strlen(base64_decode($request->header['sec-websocket-key']))
		) {
			//Header Sec-WebSocket-Key is illegal;
			$response->end();
			return false;
		}
		$key = base64_encode(sha1($request->header['sec-websocket-key']
			. '258EAFA5-E914-47DA-95CA-C5AB0DC85B11',
		true));
		$headers = array(
			'Upgrade'               => 'websocket',
			'Connection'            => 'Upgrade',
			'Sec-WebSocket-Accept'  => $key,
			'Sec-WebSocket-Version' => '13',
			'KeepAlive'             => 'off',
		);
		foreach ($headers as $key => $val) {
			$response->header($key, $val);
		}
		$response->status(101);
		$response->end();


		// parse channels
		$split = explode('/', $request->server["request_uri"]);
		foreach ($split as $channel) {
			if (!$channel) continue;
			$split = explode('.',$channel);
			$channel = $split[0];
			$before = intval(substr($split[1] ?: '',1));

			$this->channels[$channel]['users'][$request->fd] = 1;
			$this->sendUsersCount($channel, $request->fd);

			// send history
			for ($i = $before-1; $i >= 0; $i--) {
				if (!isset($this->channels[$channel]['history'][$i])) continue;
				$this->push($channel, $this->channels[$channel]['history'][$i], $request->fd);
			}
		}

		return true;
	}

	protected function outClient($request, $response)
	{
		$response->end(<<<HTML
	<span>Swoole WebSocket Server</span>
	<script>
	var wsServer = 'ws://sanstv.ru:8088/ws/common/page1';
	var websocket = new WebSocket(wsServer);
	websocket.onopen = function (evt) {
		console.log("Connected to WebSocket server.");
	};
	websocket.onclose = function (evt) {
		console.log("Disconnected");
	};
	websocket.onmessage = function (evt) {
		console.log('Retrieved data from server: ' + evt.data);
	};
	websocket.onerror = function (evt, e) {
		console.log('Error occured: ' + evt.data);
	};
</script>
HTML
		);
	}

	public function push($channel, $data=[], $fd=0, $save=false)
	{
		$packet = [
			'channel' => $channel,
			'data' => $data,
		];
		if ($fd) $packet['fd'] = $fd;
		$json = json_encode($packet);

		if ($fd) {
			$this->server->push($fd, $json);
			return 1;
		}

		// insert to history
		if ($save) {
			$this->channels[$channel]['history'] = $this->channels[$channel]['history'] ?: [];
			array_unshift($this->channels[$channel]['history'], $data);
			$this->channels[$channel]['history'] = array_slice($this->channels[$channel]['history'], 0, 10);
		}

		foreach ($this->channels[$channel]['users'] ?:[] as $fd => $v){
			if (!$this->server->push($fd, $json)){
				unset($this->channels[$channel]['users'][$fd]);
			}
		}
		return count($this->channels[$channel]['users']);
	}

	protected function sendUsersCountTimer($param)
	{
		$cnt = count($this->channels[$param['channel']]['users']);
		if ($this->channels[$param['channel']]['last'] != $cnt) {
			$this->push($param['channel'],  json_encode([ 'usersCount' => $cnt ]) );
			$this->channels[$param['channel']]['last'] = $cnt;
		}
		$this->channels[$param['channel']]['time'] = time();
		unset($this->channels[$param['channel']]['timer']);
		return true;
	}

	public function sendUsersCount($channel, $fd=0)
	{
		if ($channel == 'common'){
			return false;
		}

		if ($fd) {
			$this->push($channel,  json_encode([ 'usersCount' => count($this->channels[$channel]['users']) ]), $fd );
		}

		if ($this->channels[$channel]['timer']) {
			return false;
		}

		if ($this->channels[$channel]['time'] + 5 < time()){
			// passed more than 5 secs from last send
			return $this->sendUsersCountTimer(['channel' => $channel]);
		}

		$this->channels[$channel]['timer'] = swoole_timer_after(5000, [$this, 'sendUsersCountTimer'], ['channel' => $channel]);
		return true;
	}


	static function pub($channel='common', $message='', $save=1) {
		$ch = curl_init('http://127.0.0.1:8088/pub?id='.urlencode($channel).'&save='.$save);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,   // return web page
			CURLOPT_HEADER         => false,  // don't return headers
			CURLOPT_FOLLOWLOCATION => true,   // follow redirects
			CURLOPT_MAXREDIRS      => 10,     // stop after 10 redirects
			CURLOPT_ENCODING       => "",     // handle compressed
			CURLOPT_USERAGENT      => "",     // name of client
			CURLOPT_AUTOREFERER    => true,   // set referrer on redirect
			CURLOPT_CONNECTTIMEOUT => 1,      // time-out on connect
			CURLOPT_TIMEOUT        => 1,      // time-out on response
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => json_encode($message),
		]);
		$content = curl_exec($ch);
		$content = json_decode($content, true);
		curl_close($ch);
		return $content;
	}

}