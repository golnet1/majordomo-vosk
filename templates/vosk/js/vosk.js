(function () {
    var cfg = window.VOSK_CONFIG || {};
    var triggerPhrases = cfg.triggerPhrases || [];
    if (triggerPhrases.length === 0 && cfg.triggerPhrase) {
        triggerPhrases = [cfg.triggerPhrase.toLowerCase()];
    }
    if (triggerPhrases.length === 0) {
        triggerPhrases = ['мажордом'];
    }
    var apiUrl = cfg.apiUrl || '/api.php/module/vosk/';

    var state = 'idle';
    var ready = false;
    var audioCtx = null;
    var stream = null;
    var processor = null;
    var source = null;
    var chunks = [];
    var recording = false;
    var silenceStart = 0;
    var vadSilenceMs = 1500;
    var vadThreshold = 0.02;
    var waitTimer = null;
    var waitTimeoutMs = 5000;

    var statusEl = null;
    var textEl = null;
    var hintText = 'Фраза-триггер: ' + triggerPhrases.join(', ');

    function initUI() {
        var container = document.getElementById('vosk-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'vosk-container';
            container.style.cssText = 'display:none';
            document.body.appendChild(container);
        }
        statusEl = document.createElement('div');
        statusEl.id = 'vosk-status';
        container.appendChild(statusEl);
        textEl = document.createElement('div');
        textEl.id = 'vosk-text';
        textEl.textContent = hintText;
        textEl.style.cssText = 'color:#999;font-style:italic;';
        container.appendChild(textEl);
    }

    function log(msg) {
        if (statusEl) statusEl.textContent = msg;
    }

    var audioCtx2 = null;

    function ensureAudioCtx() {
        if (!audioCtx2) audioCtx2 = new (window.AudioContext || window.webkitAudioContext)();
        if (audioCtx2.state === 'suspended') audioCtx2.resume();
        return audioCtx2;
    }

    function playTone(freq1, freq2, dur1, dur2, gap) {
        try {
            var ctx = ensureAudioCtx();
            var gain = ctx.createGain();
            gain.connect(ctx.destination);
            gain.gain.value = 0.25;

            var osc1 = ctx.createOscillator();
            osc1.type = 'sine';
            osc1.frequency.value = freq1;
            osc1.connect(gain);
            osc1.start(ctx.currentTime);
            osc1.stop(ctx.currentTime + dur1 / 1000);

            if (freq2 && dur2) {
                var osc2 = ctx.createOscillator();
                osc2.type = 'sine';
                osc2.frequency.value = freq2;
                osc2.connect(gain);
                osc2.start(ctx.currentTime + gap / 1000);
                osc2.stop(ctx.currentTime + gap / 1000 + dur2 / 1000);
            }
        } catch (e) {
            console.warn('Vosk: playTone error', e);
        }
    }

    function dingDong() { playTone(880, 660, 120, 250, 150); }
    function dongDing() { playTone(660, 880, 120, 250, 150); }

    function sendAudio(audioBlob) {
        var formData = new FormData();
        formData.append('audio', audioBlob, 'speech.wav');

        return fetch(apiUrl + 'recognize', {
            method: 'POST',
            body: audioBlob,
            headers: { 'Content-Type': 'audio/wav' }
        }).then(function (r) { return r.json(); }).then(function (data) {
            return data.text || '';
        }).catch(function (e) {
            console.warn('Vosk: recognize error', e);
            return '';
        });
    }

    function processCommand(text) {
        return fetch(apiUrl + 'process', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ text: text })
        }).then(function (r) { return r.json(); }).catch(function (e) {
            console.warn('Vosk: process error', e);
            return { ok: false };
        });
    }

    function showText(text) {
        if (!textEl) return;
        if (text) {
            textEl.textContent = text;
            textEl.style.color = '';
            textEl.style.fontStyle = '';
        } else {
            textEl.textContent = hintText;
            textEl.style.color = '#999';
            textEl.style.fontStyle = 'italic';
        }
    }

    function handleRecognition(text) {
        if (!isActive) return;
        text = text.toLowerCase().trim();
        if (!text) return;

        var matchedTrigger = '';
        var matchedIdx = -1;
        for (var i = 0; i < triggerPhrases.length; i++) {
            var idx = text.indexOf(triggerPhrases[i]);
            if (idx !== -1) {
                matchedTrigger = triggerPhrases[i];
                matchedIdx = idx;
                break;
            }
        }

        if (matchedTrigger) {
            dingDong();
            var cmd = text.substring(matchedIdx + matchedTrigger.length).trim();
            if (cmd) {
                showText(cmd);
                processCommand(cmd);
                setTimeout(dongDing, 500);
                state = 'idle';
            } else {
                state = 'waiting';
                log('Ожидаю команду...');
                if (waitTimer) clearTimeout(waitTimer);
                waitTimer = setTimeout(function () {
                    state = 'idle';
                    log('');
                }, waitTimeoutMs);
            }
        } else if (state === 'waiting' || !triggerPhrases.length) {
            showText(text);
            processCommand(text);
            setTimeout(dongDing, 500);
            state = 'idle';
            if (waitTimer) clearTimeout(waitTimer);
        }
    }

    var isActive = true;

    document.addEventListener('visibilitychange', function () {
        isActive = !document.hidden;
        if (!isActive) {
            recording = false;
            chunks = [];
            if (waitTimer) { clearTimeout(waitTimer); waitTimer = null; }
            log('');
        }
    });

    window.addEventListener('blur', function () {
        isActive = false;
        recording = false;
        chunks = [];
        if (waitTimer) { clearTimeout(waitTimer); waitTimer = null; }
        log('');
    });

    window.addEventListener('focus', function () {
        isActive = true;
    });

    function startListening() {
        if (state === 'disabled') return;
        try {
            navigator.mediaDevices.getUserMedia({ audio: true }).then(function (s) {
                stream = s;
                audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                source = audioCtx.createMediaStreamSource(stream);

                try {
                    processor = audioCtx.createScriptProcessor(4096, 1, 1);
                } catch (e) {
                    processor = audioCtx.createJavaScriptNode(4096, 1, 1);
                }

                var sampleRate = audioCtx.sampleRate;

                processor.onaudioprocess = function (e) {
                    if (state === 'disabled' || !isActive) return;
                    var input = e.inputBuffer.getChannelData(0);
                    var sum = 0;
                    for (var i = 0; i < input.length; i++) {
                        sum += input[i] * input[i];
                    }
                    var rms = Math.sqrt(sum / input.length);

                    if (rms > vadThreshold) {
                        if (!recording) {
                            recording = true;
                            chunks = [];
                            silenceStart = 0;
                        }
                        silenceStart = 0;
                        var buf = new Float32Array(input.length);
                        buf.set(input);
                        chunks.push(buf);
                    } else if (recording) {
                        if (silenceStart === 0) {
                            silenceStart = Date.now();
                        }
                        var buf = new Float32Array(input.length);
                        buf.set(input);
                        chunks.push(buf);

                        if (Date.now() - silenceStart > vadSilenceMs) {
                            recording = false;
                            silenceStart = 0;
                            var merged = mergeBuffers(chunks, sampleRate);
                            if (merged.length > 0) {
                                var wav = encodeWav(merged, sampleRate);
                                var blob = new Blob([wav], { type: 'audio/wav' });
                                sendAudio(blob).then(function (text) {
                                    handleRecognition(text);
                                });
                            }
                            chunks = [];
                        }
                    }
                };

                source.connect(processor);
                processor.connect(audioCtx.destination);
                log('Vosk: listening');
            }).catch(function (e) {
                log('Микрофон недоступен: ' + e.message);
                setTimeout(startListening, 3000);
            });
        } catch (e) {
            log('Ошибка: ' + e.message);
            setTimeout(startListening, 3000);
        }
    }

    function mergeBuffers(buffers, sampleRate) {
        var totalLen = 0;
        for (var i = 0; i < buffers.length; i++) {
            totalLen += buffers[i].length;
        }
        var merged = new Float32Array(totalLen);
        var offset = 0;
        for (var j = 0; j < buffers.length; j++) {
            merged.set(buffers[j], offset);
            offset += buffers[j].length;
        }
        return merged;
    }

    function encodeWav(samples, sampleRate) {
        var numChannels = 1;
        var bitsPerSample = 16;
        var byteRate = sampleRate * numChannels * bitsPerSample / 8;
        var blockAlign = numChannels * bitsPerSample / 8;
        var dataLen = samples.length * blockAlign;
        var buffer = new ArrayBuffer(44 + dataLen);
        var view = new DataView(buffer);

        writeStr(view, 0, 'RIFF');
        view.setUint32(4, 36 + dataLen, true);
        writeStr(view, 8, 'WAVE');
        writeStr(view, 12, 'fmt ');
        view.setUint32(16, 16, true);
        view.setUint16(20, 1, true);
        view.setUint16(22, numChannels, true);
        view.setUint32(24, sampleRate, true);
        view.setUint32(28, byteRate, true);
        view.setUint16(32, blockAlign, true);
        view.setUint16(34, bitsPerSample, true);
        writeStr(view, 36, 'data');
        view.setUint32(40, dataLen, true);

        var offset = 44;
        for (var i = 0; i < samples.length; i++) {
            var s = Math.max(-1, Math.min(1, samples[i]));
            s = s < 0 ? s * 0x8000 : s * 0x7FFF;
            view.setInt16(offset, s, true);
            offset += 2;
        }
        return buffer;
    }

    function writeStr(view, offset, str) {
        for (var i = 0; i < str.length; i++) {
            view.setUint8(offset + i, str.charCodeAt(i));
        }
    }

    function fetchConfig() {
        return fetch(apiUrl + 'config').then(function (r) { return r.json(); }).then(function (data) {
            if (data.triggerPhrases && data.triggerPhrases.length) {
                triggerPhrases = data.triggerPhrases.map(function (s) { return s.toLowerCase(); });
            } else if (data.triggerPhrase) {
                triggerPhrases = [data.triggerPhrase.toLowerCase()];
            }
            if (data.apiUrl) apiUrl = data.apiUrl;
        }).catch(function () { });
    }

    function onUserClick() {
        ensureAudioCtx();
        document.removeEventListener('click', onUserClick);
    }
    document.addEventListener('click', onUserClick);

    initUI();
    var p = window.VOSK_CONFIG ? Promise.resolve() : fetchConfig();
    p.then(function () { startListening(); });
})();
