# Site Assist

Local Craft CMS plugin package: `kdgraphics/interactive-ai`

Features included:

- Floating frontend chat launcher
- Modern modal interface
- Text and voice input
- Conversation persistence across page navigation
- Current-page awareness
- Site content search through Craft entries
- Lightweight learning state from interactions and recurring topics
- Form guidance and basic form prefilling
- Friendly basic info help for time, date, and weather
- Built-in native page/article summarization
- Optional `llama.cpp` support for summaries and answer refinement inside the same web container

Optional remote AI support:

- `INTERACTIVE_AI_REMOTE_ENDPOINT`
- `INTERACTIVE_AI_REMOTE_API_KEY`
- `INTERACTIVE_AI_REMOTE_MODEL`
- `INTERACTIVE_AI_WELCOME_MESSAGE`

Notes:

- Time and date replies work inside the plugin directly.
- Weather replies use the Open-Meteo public APIs, so the runtime environment must have outbound internet access.
- The plugin now keeps a lightweight learning state in Craft storage so it can remember recurring topics, recent pages, and preferences over time.
- For deeper adaptive reasoning across website content and broader internet knowledge, connect a remote AI endpoint.

Optional `llama.cpp` inside the web container:

Site Assist can optionally use `llama.cpp` for:

- page/article summaries
- selected answer refinement

This is optional. If `llama.cpp` is not installed, the plugin falls back to the built-in native summarizer and local response engine.

Craft settings to configure:

- `Enable llama.cpp`
- `llama.cpp Binary Path`
- `Model Path`
- `Context Size`
- `Use llama.cpp for summaries`
- `Use llama.cpp for answers`

Typical same-container install steps:

1. Install build tools in the web container image
2. Build or install `llama.cpp`
3. Download a GGUF model into a persistent path
4. Point Site Assist settings to the binary and model

Example generic install flow for a Linux-based web container:

```bash
apt-get update
apt-get install -y build-essential cmake git curl
git clone https://github.com/ggerganov/llama.cpp.git /opt/llama.cpp
cmake -S /opt/llama.cpp -B /opt/llama.cpp/build
cmake --build /opt/llama.cpp/build --config Release -j
mkdir -p /var/www/html/storage/interactive-ai/models
cd /var/www/html/storage/interactive-ai/models
curl -L -o model.gguf "YOUR_GGUF_MODEL_URL"
```

Typical paths after build:

- binary: `/opt/llama.cpp/build/bin/llama-cli`
- model: `/var/www/html/storage/interactive-ai/models/model.gguf`

Suggested Craft settings:

- `Enable llama.cpp`: on
- `llama.cpp Binary Path`: `/opt/llama.cpp/build/bin/llama-cli`
- `Model Path`: `/var/www/html/storage/interactive-ai/models/model.gguf`
- `Context Size`: `4096`

Container notes:

- keep the model outside the plugin source tree
- use a mounted/persistent storage path for GGUF files
- CPU-only inference is usually enough for summaries and answer polishing, but may be slower for longer responses
- for easiest deployment across different projects, bake the binary into the web image or install it in a startup script, then configure the plugin paths in Craft

DDEV-specific setup:

This repository includes a non-active DDEV example file:

- `.ddev/web-build/Dockerfile.site-assist-llama.example`
- `.ddev/.env.web.site-assist-llama.example`

To enable it:

1. Rename it to:

```bash
mv .ddev/web-build/Dockerfile.site-assist-llama.example .ddev/web-build/Dockerfile.site-assist-llama
```

2. Rebuild the DDEV web container:

```bash
ddev restart
```

3. Copy the example env values into `.ddev/.env.web` if you want the helper command to use the same paths:

```bash
cat .ddev/.env.web.site-assist-llama.example >> .ddev/.env.web
```

4. Restart DDEV if you changed `.ddev/.env.web`:

```bash
ddev restart
```

5. Put a GGUF model in a persistent path inside the project, for example:

```bash
ddev exec mkdir -p /var/www/html/storage/interactive-ai/models
```

6. Copy or download your model to:

```bash
/var/www/html/storage/interactive-ai/models/model.gguf
```

7. Check the paths from inside DDEV:

```bash
ddev site-assist-llama-status
```

Suggested DDEV/Craft settings:

- `Enable llama.cpp`: on
- `llama.cpp Binary Path`: `/usr/local/bin/llama-cli`
- `Model Path`: `/var/www/html/storage/interactive-ai/models/model.gguf`
- `Use llama.cpp for summaries`: on
- `Use llama.cpp for answers`: on
