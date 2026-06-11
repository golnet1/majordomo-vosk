# Vosk ASR Module for Majordomo

Модуль голосового управления для [Majordomo](https://github.com/sergejey/majordomo) с использованием Vosk (offline speech recognition) и Sherpa-Onnx. Обеспечивает постоянно слушающий микрофон в браузере, реакцию на фразу-триггер, звуковые оповещения, поддержку локального и удалённого ASR-сервера, управление моделями.

---

## English

### Overview

Voice control module for [Majordomo](https://github.com/sergejey/majordomo) smart home system. Uses Vosk offline speech recognition engine (or Sherpa-Onnx) to provide hands-free voice control through the browser.

**Key Features:**
- Always-on microphone in the browser (Web Audio API + VAD)
- Configurable trigger phrase (wake word) — default: "мажордом"
- Multiple trigger phrases (comma or semicolon separated)
- Push-to-talk mode (legacy)
- Local ASR server (Python + Vosk/Sherpa-Onnx)
- Remote ASR server support (separate machine)
- Model management: install, activate, delete (via web UI)
- Automatic model download from HuggingFace (no git required)
- Automatic systemd service setup for the ASR server
- Voice feedback via Piper TTS (optional, registered as a subscription)
- Chrome Extension for insecure HTTP sites

### Architecture

```
Browser (JS) → Web Audio API → VAD → WAV → HTTP POST → vosk_server.py → Recognized text
                                                                             ↓
                                                                    majordomo (COMMAND subscription)
                                                                             ↓
                                                                   patterns.checkAllPatterns()
                                                                             ↓
                                                                         Action!
```

### Requirements

- Linux server (tested on Debian/Ubuntu)
- PHP 8.x
- Python 3.10+
- Apache with mod_rewrite
- Majordomo (any recent version)
- (Optional) USB microphone for server-side listening

### Quick Start

1. Copy `modules/` and `templates/` to your Majordomo installation
2. Copy `languages/vosk_ru.php` to your language directory
3. Navigate to Admin → Modules → Install Vosk ASR
4. Install Python dependencies: `pip install -r modules/vosk/lib/requirements.txt`
5. Install a model via the web interface (Models tab)
6. Open any page with the microphone and start speaking

### Recognition Modes

#### Local (default)

PHP calls `vosk_asr.py` directly via `exec()` — no server needed. Just install the model and the module handles the rest. Leave the "Remote ASR URL" setting empty.

#### Remote (separate ASR server)

Run `vosk_server.py` on a dedicated Linux server, then set its IP:port in module settings.

```bash
# On the remote server (auto-creates systemd service with sudo)
sudo python3 modules/vosk/lib/vosk_server.py --models-dir /opt/vosk/models --port 5001

# Without systemd (for testing)
python3 modules/vosk/lib/vosk_server.py --models-dir /opt/vosk/models --port 5001 --no-service
```

In module settings, specify `IP:port` (e.g. `192.168.1.100:5001`).

### Models

Pre-configured models (auto-install via web UI):
- `vosk-model-small-ru-0.22` — Kaldi, ~88 MB, fast
- `vosk-model-ru-0.42` — Kaldi, ~1.8 GB, accurate
- `vosk-model-ru-0.54` — Sherpa-Onnx Zipformer2, ~1.9 GB, most accurate

### Chrome Flag for HTTP

If using HTTP (not HTTPS):
1. Open `chrome://flags`
2. Search for `#unsafely-treat-insecure-origin-as-secure`
3. Add your server's URL (e.g., `http://dacha`)
4. Relaunch Chrome

### Project Structure

```
majordomo-vosk/
├── modules/
│   ├── prepend.php                              # Module bootstrapper
│   └── vosk/
│       ├── vosk.class.php                       # Main module class
│       ├── prepend.php                          # Front-end injector
│       └── lib/
│           ├── vosk_server.py                   # ASR HTTP server
│           ├── vosk_asr.py                      # Recognition helper
│           ├── install_model.php                # Model installer
│           └── requirements.txt                 # Python deps
├── templates/vosk/
│   ├── vosk.html                                # Main template
│   ├── settings.html                            # Settings form
│   ├── models.html                              # Model management
│   ├── help.html                                # Help page
│   ├── ptt.html                                 # Push-to-talk (legacy)
│   ├── action_admin.html                        # Admin actions
│   └── js/vosk.js                               # Client-side JS
├── languages/vosk_ru.php                        # Russian localization
├── scripts/cycle_vosk.php                       # Cron maintenance
├── img/modules/vosk.png                         # Module icon
├── downloads/vosk-mic.zip                      # Chrome extension
└── README.md
```

---

## Русский

### Обзор

Модуль голосового управления для системы умного дома [Majordomo](https://github.com/sergejey/majordomo). Использует offline-распознавание речи Vosk (или Sherpa-Onnx) для голосового управления через браузер без сторонних сервисов.

**Основные возможности:**
- Постоянно слушающий микрофон в браузере (Web Audio API + VAD)
- Настраиваемая фраза-триггер (по умолчанию: "мажордом")
- Несколько фраз-триггеров (через запятую или точку с запятой)
- Режим Push-to-Talk (включён по умолчанию)
- Локальный ASR-сервер (Python + Vosk/Sherpa-Onnx)
- Удалённый ASR-сервер (отдельная машина)
- Управление моделями: установка, активация, удаление (через веб-интерфейс)
- Автоматическая загрузка моделей с HuggingFace (без git)
- Автоустановка systemd-сервиса для ASR-сервера
- Голосовая обратная связь через Piper TTS (опционально, подписка COMMAND)
- Расширение Chrome для работы на HTTP

### Архитектура

```
Браузер (JS) → Web Audio API → VAD → WAV → HTTP POST → vosk_server.py → Распознанный текст
                                                                                ↓
                                                                       majordomo (COMMAND подписка)
                                                                                ↓
                                                                      patterns.checkAllPatterns()
                                                                                ↓
                                                                            Действие!
```

### Требования

- Linux-сервер (проверено на Debian/Ubuntu)
- PHP 8.x
- Python 3.10+
- Apache с mod_rewrite
- Majordomo (любая свежая версия)
- (Опционально) USB-микрофон для прослушивания на сервере

### Быстрый старт

1. Скопируйте `modules/` и `templates/` в установку Majordomo
2. Скопируйте `languages/vosk_ru.php` в директорию языков
3. Зайдите в Администрирование → Модули → Установить Vosk ASR
4. Установите Python-зависимости: `pip install -r modules/vosk/lib/requirements.txt`
5. Установите модель через веб-интерфейс (вкладка «Модели»)
6. Откройте любую страницу с микрофоном и начинайте говорить

### Режимы распознавания

#### Локальный (по умолчанию)

PHP вызывает `vosk_asr.py` напрямую через `exec()` — запускать сервер не нужно. Достаточно установить модель. Поле «REMOTE_ASR_URL» в настройках оставьте пустым.

#### Удалённый (отдельный ASR-сервер)

Запустите `vosk_server.py` на выделенном Linux-сервере, укажите его IP:порт в настройках модуля.

```bash
# На удалённом сервере (автоустановка systemd через sudo)
sudo python3 modules/vosk/lib/vosk_server.py --models-dir /opt/vosk/models --port 5001

# Без systemd (для тестирования)
python3 modules/vosk/lib/vosk_server.py --models-dir /opt/vosk/models --port 5001 --no-service
```

В настройках модуля укажите `IP:порт` (например, `192.168.1.100:5001`).

### Модели

Предустановленные модели (автоустановка через веб-интерфейс):
- `vosk-model-small-ru-0.22` — Kaldi, ~88 МБ, быстро, базовая точность
- `vosk-model-ru-0.42` — Kaldi, ~1.8 ГБ, высокая точность
- `vosk-model-ru-0.54` — Sherpa-Onnx Zipformer2, ~1.9 ГБ, максимальная точность

### Флаг Chrome для HTTP

Если сайт работает по HTTP (не HTTPS):
1. Откройте `chrome://flags`
2. Найдите `#unsafely-treat-insecure-origin-as-secure`
3. Добавьте URL сервера (например, `http://dacha`)
4. Перезапустите Chrome

### Структура проекта

```
majordomo-vosk/
├── modules/
│   ├── prepend.php                              # Загрузчик модулей
│   └── vosk/
│       ├── vosk.class.php                       # Основной класс модуля
│       ├── prepend.php                          # Инжектор в браузер
│       └── lib/
│           ├── vosk_server.py                   # ASR HTTP-сервер
│           ├── vosk_asr.py                      # Распознавание речи
│           ├── install_model.php                # Установщик моделей
│           └── requirements.txt                 # Python-зависимости
├── templates/vosk/
│   ├── vosk.html                                # Основной шаблон
│   ├── settings.html                            # Настройки
│   ├── models.html                              # Управление моделями
│   ├── help.html                                # Справка
│   ├── ptt.html                                 # Push-to-Talk (legacy)
│   ├── action_admin.html                        # Действия администратора
│   └── js/vosk.js                               # Клиентский JS
├── languages/vosk_ru.php                        # Русская локализация
├── scripts/cycle_vosk.php                       # Cron-скрипт
├── img/modules/vosk.png                         # Иконка модуля
├── downloads/vosk-mic.zip                      # Расширение Chrome
└── README.md
```

---

## License

MIT
