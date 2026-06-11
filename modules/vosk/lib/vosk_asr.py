#!/usr/bin/env python3
import argparse
import json
import os
import signal
import sys
import time
import wave
import struct

import numpy as np

def is_sherpa_onnx_model(model_path):
    return os.path.isfile(os.path.join(model_path, 'am-onnx', 'encoder.onnx'))

def recognize_file(model_path, audio_path, sample_rate=16000):
    if not os.path.isfile(audio_path):
        return {'error': f'File not found: {audio_path}'}

    if is_sherpa_onnx_model(model_path):
        return recognize_sherpa_onnx(model_path, audio_path, sample_rate)
    else:
        return recognize_vosk(model_path, audio_path, sample_rate)

def recognize_sherpa_onnx(model_path, audio_path, sample_rate=16000):
    import sherpa_onnx

    wf = wave.open(audio_path, 'rb')
    if wf.getnchannels() != 1 or wf.getsampwidth() != 2:
        wf.close()
        return {'error': 'Audio must be 16-bit mono WAV'}

    actual_sr = wf.getframerate()
    num_samples = wf.getnframes()
    samples = wf.readframes(num_samples)
    wf.close()
    samples_int16 = np.frombuffer(samples, dtype=np.int16)
    samples_float32 = samples_int16.astype(np.float32) / 32768

    recognizer = sherpa_onnx.OfflineRecognizer.from_transducer(
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

    stream = recognizer.create_stream()
    stream.accept_waveform(actual_sr, samples_float32)
    recognizer.decode_stream(stream)

    text = stream.result.text.strip()
    return {'text': text, 'success': bool(text)}

def recognize_vosk(model_path, audio_path, sample_rate=16000):
    import vosk

    wf = wave.open(audio_path, 'rb')
    if wf.getnchannels() != 1 or wf.getsampwidth() != 2:
        return {'error': 'Audio must be 16-bit mono WAV'}

    model = vosk.Model(model_path)
    rec = vosk.KaldiRecognizer(model, wf.getframerate())
    rec.SetWords(False)

    result_text = ''
    while True:
        data = wf.readframes(4000)
        if len(data) == 0:
            break
        if rec.AcceptWaveform(data):
            result = json.loads(rec.Result())
            text = result.get('text', '')
            if text:
                result_text += ' ' + text

    final = json.loads(rec.FinalResult())
    text = final.get('text', '')
    if text:
        result_text += ' ' + text

    wf.close()
    return {'text': result_text.strip(), 'success': True}


def mic_listen(model_path, sample_rate=16000, device=None, trigger='',
               api_url='', pid_file='', status_file=''):
    import pyaudio as pa

    if pid_file:
        with open(pid_file, 'w') as f:
            f.write(str(os.getpid()))

    if status_file:
        with open(status_file, 'w') as f:
            json.dump({'last_text': '', 'last_time': int(time.time())}, f)

    def signal_handler(signum, frame):
        sys.exit(0)

    signal.signal(signal.SIGTERM, signal_handler)
    signal.signal(signal.SIGINT, signal_handler)

    p = pa.PyAudio()
    stream = p.open(
        format=pa.paInt16,
        channels=1,
        rate=sample_rate,
        input=True,
        input_device_index=int(device) if device else None,
        frames_per_buffer=4000,
    )
    stream.start_stream()

    if is_sherpa_onnx_model(model_path):
        import sherpa_onnx
        recognizer = sherpa_onnx.OfflineRecognizer.from_transducer(
            encoder=os.path.join(model_path, 'am-onnx', 'encoder.onnx'),
            decoder=os.path.join(model_path, 'am-onnx', 'decoder.onnx'),
            joiner=os.path.join(model_path, 'am-onnx', 'joiner.onnx'),
            tokens=os.path.join(model_path, 'lang', 'tokens.txt'),
            num_threads=2,
            provider='cpu',
            sample_rate=16000,
        )
        batch_size = 20
        buf = []
    else:
        import vosk
        model = vosk.Model(model_path)
        recognizer = vosk.KaldiRecognizer(model, sample_rate)
        recognizer.SetWords(False)

    print(f"Vosk mic listening started. PID: {os.getpid()}")

    while True:
        data = stream.read(4000, exception_on_overflow=False)

        if is_sherpa_onnx_model(model_path):
            buf.append(data)
            if len(buf) >= batch_size:
                raw = b''.join(buf)
                samples_int16 = np.frombuffer(raw, dtype=np.int16)
                samples_float32 = samples_int16.astype(np.float32) / 32768
                s = recognizer.create_stream()
                s.accept_waveform(sample_rate, samples_float32)
                recognizer.decode_stream(s)
                text = s.result.text.strip()
                buf = []
                if text:
                    print(f"Recognized: {text}")
                    if status_file:
                        with open(status_file, 'w') as f:
                            json.dump({'last_text': text, 'last_time': int(time.time())}, f)
        else:
            if recognizer.AcceptWaveform(data):
                result = json.loads(recognizer.Result())
                text = result.get('text', '').strip()
                if text:
                    print(f"Recognized: {text}")
                    if status_file:
                        with open(status_file, 'w') as f:
                            json.dump({'last_text': text, 'last_time': int(time.time())}, f)
            else:
                partial = json.loads(recognizer.PartialResult())
                ptext = partial.get('partial', '').strip()
                if ptext and status_file:
                    with open(status_file, 'w') as f:
                        json.dump({'last_text': ptext, 'last_time': int(time.time())}, f)


def main():
    parser = argparse.ArgumentParser(description='Vosk ASR for Majordomo')
    parser.add_argument('--model', required=True, help='Path to Vosk model directory')
    parser.add_argument('--sample-rate', default='16000', help='Audio sample rate')
    parser.add_argument('--file', default='', help='Process audio file and exit')
    parser.add_argument('--mic', action='store_true', help='Listen from microphone')
    parser.add_argument('--device', default='', help='Audio input device index')
    parser.add_argument('--pid-file', default='', help='PID file path')
    parser.add_argument('--status-file', default='', help='Status file path')
    args = parser.parse_args()

    if not os.path.isdir(args.model):
        print(f"ERROR: Model directory not found: {args.model}", file=sys.stderr)
        sys.exit(1)

    if args.file:
        result = recognize_file(
            args.model, args.file,
            sample_rate=int(args.sample_rate)
        )
        print(json.dumps(result, ensure_ascii=False))
        sys.exit(0 if result.get('success') else 1)

    if args.mic:
        mic_listen(
            args.model,
            sample_rate=int(args.sample_rate),
            device=args.device,
            pid_file=args.pid_file,
            status_file=args.status_file,
        )
        return

    print("ERROR: specify --file or --mic", file=sys.stderr)
    sys.exit(1)


if __name__ == '__main__':
    main()
