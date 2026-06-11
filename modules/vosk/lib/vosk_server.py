#!/usr/bin/env python3
"""
Vosk ASR HTTP server.
- POST /recognize — распознаёт WAV, возвращает {"text":"..."}
- GET  /models   — список моделей в models-dir
- POST /models/activate — переключает активную модель
- POST /models/install  — устанавливает модель по ID
- POST /models/delete   — удаляет модель по ID

Поддерживает Vosk Kaldi и Sherpa-Onnx модели.
"""
import argparse
import json
import os
import shutil
import socket
import subprocess
import sys
import tempfile
import threading
import time
import urllib.request
import wave
from http.server import HTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlparse

import numpy as np

CONFIG_FILE = '/tmp/vosk_server_config.json'

SHERPA_PACKAGE = 'sherpa-onnx'

_installing = {}
_INSTALL_TIMEOUT = 1800
_installing_lock = threading.Lock()

MODELS = {
    'vosk-model-small-ru-0.22': {
        'url': 'https://github.com/kercre123/vosk-models/raw/main/vosk-model-small-ru-0.22.zip',
        'type': 'vosk',
        'git': '',
    },
    'vosk-model-ru-0.22': {
        'url': 'https://alphacephei.com/vosk/models/vosk-model-ru-0.22.zip',
        'type': 'vosk',
        'git': '',
    },
    'vosk-model-ru-0.42': {
        'url': 'https://github.com/mikl-tmp/Vosk-models-for-SVI/raw/main/vosk-model-ru-0.42.zip',
        'type': 'vosk',
        'git': '',
    },
    'vosk-model-ru-0.54': {
        'url': '',
        'type': 'sherpa-onnx',
        'git': 'https://huggingface.co/alphacep/vosk-model-ru',
    },
}


def is_sherpa_onnx_model(model_path):
    return os.path.isfile(os.path.join(model_path, 'am-onnx', 'encoder.onnx'))


def ensure_sherpa_onnx():
    try:
        import sherpa_onnx  # noqa: F401
        return True
    except ImportError:
        print("sherpa-onnx not found, installing...")
        r = subprocess.run([sys.executable, '-m', 'pip', 'install', SHERPA_PACKAGE],
                           capture_output=True, text=True)
        if r.returncode != 0:
            raise RuntimeError("Failed to install sherpa-onnx: " + r.stderr)
        print("sherpa-onnx installed successfully")
        return True


def create_recognizer(model_path):
    if is_sherpa_onnx_model(model_path):
        ensure_sherpa_onnx()
        import sherpa_onnx
        rec = sherpa_onnx.OfflineRecognizer.from_transducer(
            encoder=os.path.join(model_path, 'am-onnx', 'encoder.onnx'),
            decoder=os.path.join(model_path, 'am-onnx', 'decoder.onnx'),
            joiner=os.path.join(model_path, 'am-onnx', 'joiner.onnx'),
            tokens=os.path.join(model_path, 'lang', 'tokens.txt'),
            num_threads=2,
            provider='cpu',
            sample_rate=16000,
            dither=3e-5,
            max_active_paths=10,
            decoding_method='modified_beam_search',
        )
        return ('sherpa', rec)
    else:
        import vosk
        model = vosk.Model(model_path)
        return ('vosk', model)


def download_hf_repo(repo_url, dest):
    """Download a HuggingFace model repo via HTTP API without git."""
    repo_id = repo_url.rstrip('/').replace('https://huggingface.co/', '')
    api_url = 'https://huggingface.co/api/models/' + repo_id
    print("Fetching file list from", api_url)
    req = urllib.request.Request(api_url, headers={'User-Agent': 'Mozilla/5.0'})
    with urllib.request.urlopen(req, timeout=30) as resp:
        data = json.loads(resp.read().decode('utf-8'))
    siblings = data.get('siblings', [])
    if not siblings:
        # fallback: try tree endpoint
        import re
        tree_url = 'https://huggingface.co/' + repo_id + '/tree/main'
        req2 = urllib.request.Request(tree_url, headers={'User-Agent': 'Mozilla/5.0'})
        with urllib.request.urlopen(req2, timeout=30) as resp2:
            html = resp2.read().decode('utf-8')
        # parse file links from the directory listing
        matches = re.findall(r'href="(/' + repo_id + r'/resolve/main/[^"]+)"', html)
        for m in matches:
            siblings.append({'rfilename': m.split('/resolve/main/')[1]})

    total = len(siblings)
    for i, sib in enumerate(siblings, 1):
        path = sib.get('rfilename', '')
        if not path:
            continue
        file_url = 'https://huggingface.co/' + repo_id + '/resolve/main/' + path
        local_path = os.path.join(dest, path)
        os.makedirs(os.path.dirname(local_path), exist_ok=True)
        print("  [%d/%d] %s" % (i, total, path))
        try:
            socket.setdefaulttimeout(60)
            urllib.request.urlretrieve(file_url, local_path)
        except Exception as e:
            print("    skip (%s)" % str(e))
        finally:
            socket.setdefaulttimeout(None)
    print("Download complete:", dest)


def install_model(model_id, models_dir):
    info = MODELS.get(model_id)
    if not info:
        raise ValueError("Unknown model: " + model_id)
    dest = os.path.join(models_dir, model_id)

    if info['git']:
        if os.path.isdir(dest):
            shutil.rmtree(dest, ignore_errors=True)
        os.makedirs(dest, exist_ok=True)
        download_hf_repo(info['git'], dest)
        if info['type'] == 'sherpa-onnx':
            ensure_sherpa_onnx()
    else:
        if not os.path.isdir(dest):
            os.makedirs(dest, exist_ok=True)
        zip_path = os.path.join(dest, model_id + '.zip')
        import zipfile
        print("Downloading", info['url'])
        socket.setdefaulttimeout(3600)
        try:
            urllib.request.urlretrieve(info['url'], zip_path)
        finally:
            socket.setdefaulttimeout(None)
        with zipfile.ZipFile(zip_path, 'r') as zf:
            zf.extractall(models_dir)
        os.unlink(zip_path)
    return dest


def delete_model(model_id, models_dir):
    dest = os.path.join(models_dir, model_id)
    try:
        if os.path.isdir(dest):
            shutil.rmtree(dest, ignore_errors=True)
    except Exception:
        pass
    return dest


def get_active_path():
    if os.path.exists(CONFIG_FILE):
        try:
            with open(CONFIG_FILE) as f:
                cfg = json.load(f)
            p = cfg.get('active_model', '')
            if p and os.path.isdir(p):
                return p
        except Exception:
            pass
    return ''


def save_active_path(path):
    with open(CONFIG_FILE, 'w') as f:
        json.dump({'active_model': path}, f)


class VoskHandler(BaseHTTPRequestHandler):
    model = None
    model_type = 'vosk'
    models_dir = ''

    def do_GET(self):
        parsed = urlparse(self.path)
        if parsed.path == '/models':
            self.handle_list_models()
        else:
            self.send_json({'error': 'Not found'}, 404)

    def do_POST(self):
        parsed = urlparse(self.path)
        length = int(self.headers.get('Content-Length', 0))
        body = self.rfile.read(length) if length > 0 else b''
        try:
            if parsed.path == '/recognize':
                self.handle_recognize(body)
            elif parsed.path == '/models/activate':
                self.handle_activate_model(body)
            elif parsed.path == '/models/install':
                self.handle_install_model(body)
            elif parsed.path == '/models/delete':
                self.handle_delete_model(body)
            else:
                self.send_json({'error': 'Not found'}, 404)
        except Exception as e:
            self.send_json({'error': str(e)}, 500)

    def handle_recognize(self, audio_data):
        if not audio_data:
            self.send_json({'text': '', 'success': False}, 400)
            return
        try:
            text = self.recognize(audio_data)
            self.send_json({'text': text, 'success': bool(text)})
        except Exception as e:
            self.send_json({'error': str(e)}, 500)

    def handle_list_models(self):
        if not self.models_dir or not os.path.isdir(self.models_dir):
            self.send_json({'models': [], 'active': '', 'error': 'models_dir not found'})
            return
        now = time.time()
        installing = {}
        stale = []
        with _installing_lock:
            for mid, ts in list(_installing.items()):
                if now - ts > _INSTALL_TIMEOUT:
                    stale.append(mid)
                else:
                    installing[mid] = ts
            for mid in stale:
                _installing.pop(mid, None)
        items = []
        for name in sorted(os.listdir(self.models_dir)):
            path = os.path.join(self.models_dir, name)
            if os.path.isdir(path) and not name.startswith('.'):
                mtype = 'sherpa-onnx' if is_sherpa_onnx_model(path) else 'vosk'
                item = {'id': name, 'path': path, 'type': mtype}
                if name in installing:
                    item['installing'] = True
                items.append(item)
        for name in sorted(installing):
            path = os.path.join(self.models_dir, name)
            if not os.path.isdir(path):
                items.append({'id': name, 'path': '', 'type': 'vosk', 'installing': True})
        items.sort(key=lambda x: x['id'])
        active = VoskHandler.get_active_path()
        self.send_json({'models': items, 'active': active})

    def handle_activate_model(self, body):
        data = json.loads(body)
        path = data.get('path', '')
        if not path:
            self.send_json({'error': 'path is empty'}, 400)
            return
        if not os.path.isdir(path):
            full = os.path.join(self.models_dir, path)
            if os.path.isdir(full):
                path = full
            else:
                self.send_json({'error': 'model path not found: ' + path}, 400)
                return
        mtype, rec = create_recognizer(path)
        VoskHandler.model_type = mtype
        VoskHandler.model = rec
        VoskHandler.save_active_path(path)
        self.send_json({'ok': True, 'active': path})

    def handle_install_model(self, body):
        data = json.loads(body)
        model_id = data.get('id', '')
        if not model_id:
            self.send_json({'error': 'model id is empty'}, 400)
            return
        if model_id not in MODELS:
            self.send_json({'error': 'unknown model: ' + model_id}, 400)
            return
        with _installing_lock:
            _installing[model_id] = time.time()
        self.send_json({'ok': True, 'status': 'started', 'id': model_id})
        t = threading.Thread(target=VoskHandler._install_model_wrapper, args=(model_id, self.models_dir), daemon=True)
        t.start()

    @staticmethod
    def _install_model_wrapper(model_id, models_dir):
        try:
            install_model(model_id, models_dir)
        finally:
            with _installing_lock:
                _installing.pop(model_id, None)

    def handle_delete_model(self, body):
        data = json.loads(body)
        model_id = data.get('id', '')
        if not model_id:
            self.send_json({'error': 'model id is empty'}, 400)
            return
        dest = os.path.join(self.models_dir, model_id)
        try:
            if not os.path.isdir(dest):
                self.send_json({'error': 'model not found: ' + model_id}, 400)
                return
            active = VoskHandler.get_active_path()
            if active:
                try:
                    if os.path.samefile(dest, active):
                        VoskHandler.model = None
                        VoskHandler.model_type = 'vosk'
                        VoskHandler.save_active_path('')
                except OSError:
                    pass
            delete_model(model_id, self.models_dir)
            self.send_json({'ok': True})
        except Exception as e:
            self.send_json({'error': 'delete failed: ' + str(e)}, 500)

    def recognize(self, audio_data):
        tmp = tempfile.NamedTemporaryFile(suffix='.wav', delete=False)
        try:
            tmp.write(audio_data)
            tmp.close()
            wf = wave.open(tmp.name, 'rb')
            if wf.getnchannels() != 1 or wf.getsampwidth() != 2:
                wf.close()
                return ''

            if self.model_type == 'sherpa':
                num_samples = wf.getnframes()
                samples = wf.readframes(num_samples)
                actual_sr = wf.getframerate()
                wf.close()
                samples_int16 = np.frombuffer(samples, dtype=np.int16)
                samples_float32 = samples_int16.astype(np.float32) / 32768
                stream = self.model.create_stream()
                stream.accept_waveform(actual_sr, samples_float32)
                self.model.decode_stream(stream)
                return stream.result.text.strip()
            else:
                import vosk
                rec = vosk.KaldiRecognizer(self.model, wf.getframerate())
                rec.SetWords(False)
                result_text = ''
                while True:
                    data = wf.readframes(4000)
                    if not data:
                        break
                    if rec.AcceptWaveform(data):
                        r = json.loads(rec.Result())
                        t = r.get('text', '')
                        if t:
                            result_text += ' ' + t
                final = json.loads(rec.FinalResult())
                t = final.get('text', '')
                if t:
                    result_text += ' ' + t
                wf.close()
                return result_text.strip()
        finally:
            if os.path.exists(tmp.name):
                os.unlink(tmp.name)

    @staticmethod
    def get_active_path():
        return get_active_path()

    @staticmethod
    def save_active_path(path):
        save_active_path(path)

    def send_json(self, data, code=200):
        body = json.dumps(data, ensure_ascii=False).encode('utf-8')
        self.send_response(code)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, fmt, *args):
        sys.stderr.write('[%s] %s\n' % (self.log_date_time_string(), fmt % args))


def ensure_systemd_service(script_path, port, models_dir):
    SERVICE_NAME = 'vosk-server'
    SERVICE_FILE = '/etc/systemd/system/%s.service' % SERVICE_NAME

    if os.path.exists(SERVICE_FILE):
        return

    if os.environ.get('INVOCATION_ID') or os.environ.get('JOURNAL_STREAM'):
        return

    try:
        with open('/run/systemd/system/', 'r') as _:
            pass
    except (FileNotFoundError, PermissionError):
        return

    if os.geteuid() != 0:
        print("Not running as root, skipping systemd service setup.", file=sys.stderr)
        print("Create service manually or run with sudo.", file=sys.stderr)
        return

    unit = '''[Unit]
Description=Vosk ASR Server
After=network.target

[Service]
Type=simple
ExecStart=%(python)s %(script)s --port %(port)s --models-dir %(models)s
Restart=always
RestartSec=5
User=root

[Install]
WantedBy=multi-user.target
''' % {
        'python': sys.executable,
        'script': script_path,
        'port': port,
        'models': models_dir,
    }

    try:
        with open(SERVICE_FILE, 'w') as f:
            f.write(unit)
        subprocess.check_call(['systemctl', 'daemon-reload'])
        subprocess.check_call(['systemctl', 'enable', SERVICE_NAME])
        subprocess.check_call(['systemctl', 'start', SERVICE_NAME])
        print("Systemd service '%s' created and started." % SERVICE_NAME)
        print("Service will auto-start on boot.")
        print("To stop: systemctl stop %s" % SERVICE_NAME)
        print("To disable: systemctl disable %s" % SERVICE_NAME)
        sys.exit(0)
    except Exception as e:
        print("Failed to create systemd service:", e, file=sys.stderr)
        sys.exit(1)


def main():
    ap = argparse.ArgumentParser(description='Vosk ASR HTTP Server')
    ap.add_argument('--model', help='Initial active model name (subdir in models-dir)')
    ap.add_argument('--models-dir', default='/opt/vosk/models', help='Directory with model subdirectories (default: /opt/vosk/models)')
    ap.add_argument('--host', default='0.0.0.0', help='Listen address (default: 0.0.0.0)')
    ap.add_argument('--port', type=int, default=5001, help='Listen port (default: 5001)')
    ap.add_argument('--no-service', action='store_true', help='Skip systemd service auto-setup')
    args = ap.parse_args()

    if not args.no_service:
        script_path = os.path.abspath(sys.argv[0])
        ensure_systemd_service(script_path, args.port, args.models_dir)

    models_dir = os.path.abspath(args.models_dir)
    if not os.path.isdir(models_dir):
        print("ERROR: models-dir not found:", models_dir)
        sys.exit(1)

    VoskHandler.models_dir = models_dir

    active = get_active_path()
    if active and os.path.isdir(active):
        model_path = active
        print("Restoring active model from config:", model_path)
    elif args.model:
        model_path = os.path.join(models_dir, args.model)
        if not os.path.isdir(model_path):
            print("ERROR: model not found:", model_path)
            sys.exit(1)
    else:
        candidates = sorted(os.listdir(models_dir))
        model_path = ''
        for c in candidates:
            p = os.path.join(models_dir, c)
            if os.path.isdir(p) and not c.startswith('.'):
                model_path = p
                break
        if not model_path:
            print("ERROR: no models found in", models_dir)
            sys.exit(1)
        print("Auto-selected model:", model_path)

    save_active_path(model_path)
    print("Loading model from:", model_path)
    model_type, model = create_recognizer(model_path)
    VoskHandler.model_type = model_type
    VoskHandler.model = model
    print("Model type:", model_type)
    print("Model loaded. Starting HTTP server on %s:%d" % (args.host, args.port))
    print("Models directory:", models_dir)

    server = HTTPServer((args.host, args.port), VoskHandler)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nShutting down...")
        server.shutdown()


def ensure_systemd_service(script_path, port, models_dir):
    SERVICE_NAME = 'vosk-server'
    SERVICE_FILE = '/etc/systemd/system/%s.service' % SERVICE_NAME

    if os.path.exists(SERVICE_FILE):
        return

    if os.environ.get('INVOCATION_ID') or os.environ.get('JOURNAL_STREAM'):
        return

    if not os.path.isdir('/run/systemd/system/'):
        return

    uid = os.geteuid()
    if uid != 0:
        print("Not running as root, skipping systemd service setup.")
        print("Create service manually or run with sudo.")
        return

    unit = '''[Unit]
Description=Vosk ASR Server
After=network.target

[Service]
Type=simple
ExecStart=%(python)s %(script)s --port %(port)s --models-dir %(models)s
Restart=always
RestartSec=5
User=root

[Install]
WantedBy=multi-user.target
''' % {
        'python': sys.executable,
        'script': script_path,
        'port': port,
        'models': models_dir,
    }

    try:
        with open(SERVICE_FILE, 'w') as f:
            f.write(unit)
        subprocess.check_call(['systemctl', 'daemon-reload'])
        subprocess.check_call(['systemctl', 'enable', SERVICE_NAME])
        subprocess.check_call(['systemctl', 'start', SERVICE_NAME])
        print("Systemd service '%s' created and started." % SERVICE_NAME)
        print("Service will auto-start on boot.")
        print("To stop: systemctl stop %s" % SERVICE_NAME)
        print("To disable: systemctl disable %s" % SERVICE_NAME)
        sys.exit(0)
    except Exception as e:
        print("Failed to create systemd service:", e)
        sys.exit(1)


if __name__ == '__main__':
    main()
