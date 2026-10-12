"""Transparent localhost HTTP proxy for deterministic transport faults, never API mocks."""
import http.client
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import socket
import threading


class FaultProxy(ThreadingHTTPServer):
    daemon_threads = True

    def __init__(self, upstream_port):
        super().__init__(('127.0.0.1', 0), ForwardRequest)
        self.upstream_port = upstream_port
        self.drop_response_path = None
        self.drop_response_statuses = (201,)
        self.strip_origin_path = None
        self.missing_download_path = None
        self.committed_uploads = 0
        self.lock = threading.Lock()
        self.thread = threading.Thread(target=self.serve_forever, daemon=True)
        self.thread.start()

    def close(self):
        self.shutdown()
        self.server_close()
        self.thread.join(timeout=5)


class ForwardRequest(BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass

    def forward(self):
        # Forward the actual multipart bytes, including file data, without parsing/replacing them.
        body = self.rfile.read(int(self.headers.get('Content-Length', 0)))
        headers = dict(self.headers)
        headers.pop('Connection', None)
        path = self.path
        with self.server.lock:
            strip_origin = self.command == 'POST' and self.server.strip_origin_path == path
            if strip_origin:
                self.server.strip_origin_path = None
            drop_response = self.command == 'POST' and self.server.drop_response_path == path
            if drop_response:
                self.server.drop_response_path = None
            if self.server.missing_download_path == path:
                path = '/api/documents/00000000-0000-4000-8000-000000000000/download'
                self.server.missing_download_path = None
        if strip_origin:
            headers = {k: v for k, v in headers.items() if k.lower() != 'sec-fetch-site'}
        connection = http.client.HTTPConnection('127.0.0.1', self.server.upstream_port, timeout=30)
        try:
            connection.request(self.command, path, body=body, headers=headers)
            response = connection.getresponse()
            payload = response.read()  # Real Laravel response after request/transaction completion.
            if drop_response and response.status in self.server.drop_response_statuses:
                with self.server.lock:
                    self.server.committed_uploads += 1
                self.connection.shutdown(socket.SHUT_RDWR)
                self.close_connection = True
                return
            self.send_response(response.status)
            for key, value in response.getheaders():
                if key.lower() not in ['connection', 'transfer-encoding', 'content-length']:
                    self.send_header(key, value)
            self.send_header('Content-Length', str(len(payload)))
            self.end_headers()
            self.wfile.write(payload)
        except (BrokenPipeError, ConnectionResetError):
            self.close_connection = True
        finally:
            connection.close()

    do_GET = forward
    do_POST = forward
    do_PATCH = forward
    do_DELETE = forward
    do_PUT = forward
    do_HEAD = forward
