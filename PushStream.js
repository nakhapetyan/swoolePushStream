var PushStream = new function() {
	this.delay = 10;
	this.socket = 0;

	this.connect = function(url) {
		var socket = this.socket = new WebSocket(url);


		var t = this;
		socket.onclose = function(e)
		{
			setTimeout(function(){
				t.connect();
			}, 1000 * (5 + Math.round(Math.random()*30)) );
		};

		socket.onmessage = function(e)
		{
			if (!e.data) return;
			var data = JSON.parse(e.data);
			if (!data || !data.data) return;
			data.data = JSON.parse(data.data) || data.data;

			if (typeof window.CustomEvent === "function"){
				var event = new CustomEvent('PushStreamMessage', { detail: data });
				window.dispatchEvent(event);
			}

		};
	};

	this.connect();
};