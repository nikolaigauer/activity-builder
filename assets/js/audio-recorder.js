/**
 * Activity Builder — shared in-browser audio recorder widget (MediaRecorder).
 *
 * window.reflsubSetupAudioRecorder(box) turns a container element
 *   <div class="reflsub-audio-recorder" data-max-seconds="300" data-required="0">
 *     [optional server-rendered .reflsub-audio-existing block, for edit mode]
 *     <input type="file" class="reflsub-audio-input" name="…" accept="audio/*" hidden>
 *   </div>
 * into a working recorder. The CONTROLS (Record/Stop toggle, Re-record button,
 * timer, status, playback player, and a visible "upload a file instead" fallback)
 * are injected here, so the UI is defined in exactly one place; every call site
 * only supplies the container + the (context-named) file input.
 *
 * Both recording AND manual file upload populate the SAME file input, so the form
 * submits whichever the student used. Idempotent; keys off CSS classes not names.
 */
(function () {
    'use strict';

    function fmt(sec) {
        var m = Math.floor(sec / 60), s = sec % 60;
        return m + ':' + (s > 9 ? '' : '0') + s;
    }

    // Pick a MIME type the browser can actually record. Chrome/Firefox → webm,
    // Safari/iOS → mp4. Returns { mime, ext } or null to use the browser default.
    function pickMime() {
        if (typeof MediaRecorder === 'undefined' || !MediaRecorder.isTypeSupported) return null;
        var candidates = [
            { mime: 'audio/webm', ext: 'webm' },
            { mime: 'audio/ogg',  ext: 'ogg'  },
            { mime: 'audio/mp4',  ext: 'mp4'  }
        ];
        for (var i = 0; i !== candidates.length; i++) {
            if (MediaRecorder.isTypeSupported(candidates[i].mime)) return candidates[i];
        }
        return null;
    }

    function extFor(mime) {
        if (!mime) return 'webm';
        if (mime.indexOf('webm') > -1) return 'webm';
        if (mime.indexOf('ogg')  > -1) return 'ogg';
        if (mime.indexOf('mp4')  > -1 || mime.indexOf('mpeg') > -1) return 'mp4';
        return 'webm';
    }

    window.reflsubSetupAudioRecorder = function (box) {
        if (!box || box.dataset.reflsubAudioInit === '1') return;
        box.dataset.reflsubAudioInit = '1';

        var maxSeconds = parseInt(box.dataset.maxSeconds, 10) || 300;
        var input      = box.querySelector('.reflsub-audio-input');
        if (!input) return;
        var existing   = box.querySelector('.reflsub-audio-existing');
        var canRecord  = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia &&
                            typeof MediaRecorder !== 'undefined');

        // ── Build the controls (single source of truth for the UI) ──────────────
        var controls = document.createElement('div');
        controls.className = 'reflsub-audio-controls';

        var recBtn = document.createElement('button');     // Record ↔ Stop toggle
        recBtn.type = 'button';
        recBtn.className = 'reflsub-audio-toggle';
        recBtn.innerHTML = '● Record';

        var reBtn = document.createElement('button');      // Re-record (after a take)
        reBtn.type = 'button';
        reBtn.className = 'reflsub-audio-rerecord';
        reBtn.innerHTML = '↻ Re-record';
        reBtn.hidden = true;

        var timerEl = document.createElement('span');
        timerEl.className = 'reflsub-audio-timer';
        timerEl.setAttribute('aria-live', 'polite');
        timerEl.textContent = '0:00';

        controls.appendChild(recBtn);
        controls.appendChild(reBtn);
        controls.appendChild(timerEl);

        var statusEl = document.createElement('p');
        statusEl.className = 'reflsub-audio-status';

        var playback = document.createElement('audio');
        playback.className = 'reflsub-audio-playback';
        playback.controls = true;
        playback.preload  = 'metadata';
        playback.hidden   = true;

        // Visible upload fallback — wraps the (now-shown) file input with a label.
        var fallback = document.createElement('p');
        fallback.className = 'reflsub-audio-fallback';
        fallback.textContent = canRecord
            ? 'Can’t record, or already have an audio file? Upload it instead: '
            : 'Upload an audio file: ';
        input.hidden = false; // it’s a real fallback picker now, not a dummy

        box.insertBefore(controls, input);
        box.insertBefore(statusEl, input);
        box.insertBefore(playback, input);
        box.insertBefore(fallback, input);
        fallback.appendChild(input); // move the picker next to its label

        // ── State ───────────────────────────────────────────────────────────────
        var hasRecording  = !!existing;
        var recording     = false;
        var mediaRecorder = null, chunks = [], stream = null;
        var ticker = null, elapsed = 0, objectUrl = null;

        function showIdle() {
            recording = false;
            recBtn.hidden = false;
            recBtn.classList.remove('is-recording');
            recBtn.innerHTML = '● Record';
            reBtn.hidden = !hasRecording; // offer Re-record only once a take exists
        }
        function showRecording() {
            recording = true;
            recBtn.hidden = false;
            recBtn.classList.add('is-recording');
            recBtn.innerHTML = '■ Stop';
            reBtn.hidden = true;
        }
        function showRecorded() {
            recording = false;
            hasRecording = true;
            recBtn.hidden = false;
            recBtn.classList.remove('is-recording');
            recBtn.innerHTML = '● Record';
            reBtn.hidden = false;
        }

        if (!canRecord) {
            recBtn.disabled = true;
            statusEl.textContent = 'Recording isn’t supported in this browser — use the upload option below. (Try a recent Chrome, Firefox, Edge, or Safari to record.)';
        } else {
            statusEl.textContent = 'Click Record and allow microphone access. Maximum length ' + fmt(maxSeconds) + '.';
        }
        if (hasRecording) reBtn.hidden = false;

        function stopTicker() {
            if (ticker) { clearInterval(ticker); ticker = null; }
        }
        function stopTracks() {
            if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
        }
        function startTicker() {
            stopTicker();
            elapsed = 0;
            timerEl.textContent = '0:00';
            ticker = setInterval(function () {
                elapsed++;
                timerEl.textContent = fmt(elapsed) + ' / ' + fmt(maxSeconds);
                if (elapsed >= maxSeconds) stopRecording();
            }, 1000);
        }

        function stopRecording() {
            // Freeze the timer immediately — don't wait for the async onstop.
            stopTicker();
            if (mediaRecorder && mediaRecorder.state !== 'inactive') {
                mediaRecorder.stop();
            }
        }

        function startRecording() {
            navigator.mediaDevices.getUserMedia({ audio: true }).then(function (s) {
                stream = s;
                chunks = [];
                var picked = pickMime();
                try {
                    mediaRecorder = picked ? new MediaRecorder(stream, { mimeType: picked.mime })
                                           : new MediaRecorder(stream);
                } catch (e) {
                    mediaRecorder = new MediaRecorder(stream);
                }

                mediaRecorder.ondataavailable = function (ev) {
                    if (ev.data && ev.data.size > 0) chunks.push(ev.data);
                };

                mediaRecorder.onstop = function () {
                    stopTicker();
                    stopTracks();
                    var mime = mediaRecorder.mimeType || (picked ? picked.mime : 'audio/webm');
                    var blob = new Blob(chunks, { type: mime });

                    if (objectUrl) URL.revokeObjectURL(objectUrl);
                    objectUrl = URL.createObjectURL(blob);
                    playback.src = objectUrl;
                    playback.hidden = false;

                    // Put the take into the SAME file input the upload fallback uses.
                    var file = new File([blob], 'recording.' + extFor(mime), { type: mime });
                    var dt = new DataTransfer();
                    dt.items.add(file);
                    input.files = dt.files;

                    if (existing) existing.style.display = 'none';

                    showRecorded();
                    var kb = Math.round(blob.size / 1024);
                    statusEl.textContent = 'Recorded ' + fmt(elapsed) + ' (' +
                        (kb > 1024 ? (kb / 1024).toFixed(1) + ' MB' : kb + ' KB') +
                        '). Play it back above, or Re-record.';
                };

                mediaRecorder.start();   // ← actually begin capturing
                showRecording();
                playback.hidden = true;
                startTicker();
                statusEl.textContent = 'Recording… click Stop when you’re done.';
            }).catch(function (err) {
                showIdle();
                statusEl.textContent = (err && err.name === 'NotAllowedError')
                    ? 'Microphone access was blocked. Allow it in your browser’s address-bar permissions, or use the upload option below.'
                    : 'Could not start recording: ' + (err && err.message ? err.message : 'unknown error') + '. You can upload a file instead.';
            });
        }

        recBtn.addEventListener('click', function () {
            if (recording) { stopRecording(); } else { startRecording(); }
        });
        reBtn.addEventListener('click', function () {
            if (!recording) startRecording();
        });

        // Manual upload fallback: a user-picked file also drives the playback player
        // (DataTransfer assignment above does NOT fire 'change', so this is upload-only).
        input.addEventListener('change', function () {
            if (!input.files || !input.files.length) return;
            var f = input.files[0];
            if (objectUrl) URL.revokeObjectURL(objectUrl);
            objectUrl = URL.createObjectURL(f);
            playback.src = objectUrl;
            playback.hidden = false;
            if (existing) existing.style.display = 'none';
            stopTicker();
            timerEl.textContent = '0:00';
            showRecorded();
            statusEl.textContent = 'Selected “' + f.name + '”. Play it back above, record, or choose a different file.';
        });
    };

    function initAll() {
        document.querySelectorAll('.reflsub-audio-recorder').forEach(window.reflsubSetupAudioRecorder);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
