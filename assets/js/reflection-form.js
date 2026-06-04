/**
 * Activity Builder — front-end reflection form behaviours.
 * Enqueued by reflsub_enqueue_form_assets() in inc/reflection-form.php.
 *
 * Serves BOTH render paths (sections + legacy). Every block is element-guarded,
 * so it is a harmless no-op when a given feature is not on the page.
 *
 * NOTE: this file is loaded as a real script (wp_enqueue_script), NOT inlined in
 * shortcode output, so it is never run through the_content / wptexturize. That is
 * deliberate: inline JS in shortcode output gets its && corrupted to &#038;&#038;
 * by texturize when a bare "<" confuses its <script> detection. Keeping JS here
 * removes that whole class of bug.
 */
    (function() {
        var MAX_FILE_MB = 15;
        var MAX_FILE_BYTES = MAX_FILE_MB * 1024 * 1024;

        // ── Word counters ──────────────────────────────────────────────────────
        document.querySelectorAll('.reflection-form textarea[data-word-limit]').forEach(function(ta) {
            var limit   = parseInt(ta.dataset.wordLimit, 10);
            var counter = document.getElementById(ta.dataset.counterId);
            if (!counter || !limit) return;
            function update() {
                var words = ta.value.trim() === '' ? 0 : ta.value.trim().split(/\s+/).length;
                counter.textContent = words + ' / ' + limit + ' words';
                counter.style.color = words > limit ? '#d63638' : '#646970';
            }
            ta.addEventListener('input', update);
            update();
        });

        // ── LocalStorage autosave ─────────────────────────────────────────────
        var form     = document.querySelector('.reflection-form[data-page-id]');
        // Key is scoped to both page and logged-in user — prevents draft leaking
        // between users on shared machines / lab computers.
        var draftKey = form ? 'reflsub_draft_' + form.dataset.pageId + '_' + form.dataset.userId : null;
        // Clean up any legacy un-scoped key left by earlier builds.
        if ( form ) { try { localStorage.removeItem( 'reflsub_draft_' + form.dataset.pageId ); } catch(e) {} }

        if ( draftKey ) {
            if ( window.location.search.indexOf('reflection_submitted=1') !== -1 ) {
                // Successful submit — wipe the draft
                localStorage.removeItem( draftKey );
            } else {
                // Restore draft if one exists
                var saved = null;
                try { saved = JSON.parse( localStorage.getItem( draftKey ) ); } catch(e) {}
                if ( saved && Object.keys(saved).length ) {
                    var anyRestored = false;
                    Object.keys(saved).forEach(function(name) {
                        var el = form.querySelector('[name="' + name + '"]');
                        if ( el && ( el.tagName === 'TEXTAREA' || ( el.tagName === 'INPUT' && el.type === 'text' ) ) ) {
                            el.value = saved[name];
                            anyRestored = true;
                        }
                    });
                    if ( anyRestored ) {
                        var notice = document.createElement('div');
                        notice.className = 'reflection-notice reflection-info';
                        notice.style.cssText = 'display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;margin-bottom:1.5rem;';
                        notice.innerHTML = '<p style="margin:0;"><strong>Draft restored.</strong> Your previous text was saved automatically and has been filled in.</p>'
                            + '<button type="button" style="background:none;border:none;color:#2271b1;cursor:pointer;white-space:nowrap;padding:0;font-size:0.9rem;flex-shrink:0;text-decoration:underline;">Discard &amp; start fresh</button>';
                        form.parentNode.insertBefore( notice, form );
                        notice.querySelector('button').addEventListener('click', function() {
                            localStorage.removeItem( draftKey );
                            notice.remove();
                            form.querySelectorAll('textarea, input[type="text"]').forEach(function(el) { el.value = ''; });
                        });
                    }
                }

                // Save on input, debounced 2 s
                var saveTimer;
                function reflsubSaveDraft() {
                    var data = {};
                    form.querySelectorAll('textarea, input[type="text"]').forEach(function(el) {
                        if ( el.name ) data[el.name] = el.value;
                    });
                    try { localStorage.setItem( draftKey, JSON.stringify(data) ); } catch(e) {}
                }
                form.querySelectorAll('textarea, input[type="text"]').forEach(function(el) {
                    el.addEventListener('input', function() {
                        clearTimeout(saveTimer);
                        saveTimer = setTimeout( reflsubSaveDraft, 2000 );
                    });
                });
            }
        }

        // ── Total upload size guard ────────────────────────────────────────────
        // Catches oversized POSTs client-side so the text fields are never wiped.
        var POST_MAX_BYTES = ( window.reflsubForm && reflsubForm.postMaxBytes ) || 0;
        if ( form ) {
            form.addEventListener('submit', function(e) {
                var total = 0;
                form.querySelectorAll('input[type="file"]').forEach(function(input) {
                    Array.from(input.files || []).forEach(function(f) { total += f.size; });
                });
                // Use 90 % of post_max_size to leave room for text fields in the request body
                if ( total > POST_MAX_BYTES * 0.9 ) {
                    e.preventDefault();
                    var errEl = document.getElementById('reflsub-upload-error');
                    if ( !errEl ) {
                        errEl = document.createElement('div');
                        errEl.id = 'reflsub-upload-error';
                        errEl.className = 'reflection-notice reflection-error';
                        form.querySelector('.reflection-submit').insertAdjacentElement('beforebegin', errEl);
                    }
                    var mb    = ( total / 1024 / 1024 ).toFixed(1);
                    var limit = ( POST_MAX_BYTES / 1024 / 1024 ).toFixed(0);
                    errEl.innerHTML = '<p><strong>Images too large to upload.</strong> Your selected images total '
                        + mb + ' MB — the upload limit is ' + limit + ' MB. '
                        + 'Please remove some images and try again. '
                        + '<em>Your written text has not been lost.</em></p>';
                    errEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            });
        }

        // ── Drag-and-drop image zones ──────────────────────────────────────────
        // Exposed globally so dynamically-injected student image blocks can wire
        // up their own drop zones via window.reflsubSetupDropZone(zone).
        window.reflsubSetupDropZone = function(zone) {
            if (zone.dataset.reflsubInitialised === '1') return;
            zone.dataset.reflsubInitialised = '1';

            var input         = zone.querySelector('.reflsub-drop-input');
            var previews      = zone.querySelector('.reflsub-drop-previews');
            var acceptedFiles = []; // accumulates files across multiple drops/selects

            function rebuildInput() {
                var dt = new DataTransfer();
                acceptedFiles.forEach(function(f) { dt.items.add(f); });
                input.files = dt.files;
            }

            function updateCount() {
                var countEl = zone.querySelector('.reflsub-drop-count');
                if (!countEl) {
                    countEl = document.createElement('p');
                    countEl.className = 'reflsub-drop-count';
                    zone.appendChild(countEl);
                }
                if (acceptedFiles.length) {
                    countEl.textContent = acceptedFiles.length + ' image' + (acceptedFiles.length > 1 ? 's' : '') + ' ready to upload';
                    zone.classList.add('has-files');
                } else {
                    countEl.textContent = '';
                    zone.classList.remove('has-files');
                }
            }

            function addPreview(file, idx) {
                var wrap = document.createElement('div');
                wrap.className = 'reflsub-preview-wrap';
                wrap.dataset.idx = idx;

                var reader = new FileReader();
                reader.onload = function(e) {
                    var img = document.createElement('img');
                    img.src = e.target.result;
                    img.alt = file.name;
                    wrap.appendChild(img);
                };
                reader.readAsDataURL(file);

                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'reflsub-preview-remove';
                btn.setAttribute('aria-label', 'Remove ' + file.name);
                btn.innerHTML = '&times;';
                btn.addEventListener('click', function() {
                    var i = parseInt(wrap.dataset.idx, 10);
                    acceptedFiles.splice(i, 1);
                    wrap.remove();
                    // Re-index remaining wraps
                    previews.querySelectorAll('.reflsub-preview-wrap').forEach(function(w, newIdx) {
                        w.dataset.idx = newIdx;
                    });
                    rebuildInput();
                    updateCount();
                });
                wrap.appendChild(btn);
                previews.appendChild(wrap);
            }

            function addFiles(files) {
                var rejected = [];
                Array.from(files).forEach(function(f) {
                    if (f.size > MAX_FILE_BYTES) {
                        rejected.push(f.name + ' (' + (f.size / 1024 / 1024).toFixed(1) + ' MB)');
                        return;
                    }
                    // Deduplicate by name + size
                    var dupe = acceptedFiles.some(function(a) {
                        return a.name === f.name && a.size === f.size;
                    });
                    if (dupe) return;

                    var idx = acceptedFiles.length;
                    acceptedFiles.push(f);
                    addPreview(f, idx);
                });
                if (rejected.length) {
                    alert('The following file(s) exceed the 15 MB limit and were not added:\n\n' + rejected.join('\n'));
                }
                zone.classList.remove('is-over');
                rebuildInput();
                updateCount();
            }

            // File input change
            input.addEventListener('change', function() {
                if (input.files.length) {
                    addFiles(input.files);
                    // Reset so the same file can be re-added after removal,
                    // then immediately restore the accumulated files so they
                    // are present when the form is submitted.
                    input.value = '';
                    rebuildInput();
                }
            });

            // Drag events
            zone.addEventListener('dragover', function(e) {
                e.preventDefault();
                zone.classList.add('is-over');
            });
            zone.addEventListener('dragleave', function(e) {
                if (!zone.contains(e.relatedTarget)) {
                    zone.classList.remove('is-over');
                }
            });
            zone.addEventListener('drop', function(e) {
                e.preventDefault();
                zone.classList.remove('is-over');
                if (e.dataTransfer.files.length) {
                    addFiles(e.dataTransfer.files);
                }
            });
        };

        // Initial scan: bind any pre-rendered drop zones.
        document.querySelectorAll('.reflsub-drop-zone').forEach(function(zone) {
            window.reflsubSetupDropZone(zone);
        });

        // ── Existing-image removal (edit mode) ────────────────────────────────
        document.querySelectorAll('.reflsub-existing-wrap').forEach(function(wrap) {
            var container = wrap.closest('.reflsub-existing-images');
            var btn = wrap.querySelector('.reflsub-existing-remove');
            if (!btn) return;
            btn.addEventListener('click', function() {
                var hidden = document.getElementById('reflsub-keep-' + wrap.dataset.imgId);
                if (hidden) hidden.remove();
                wrap.remove();
                // Hide the whole section if all existing images were removed
                if (container && !container.querySelector('.reflsub-existing-wrap')) {
                    container.style.display = 'none';
                }
            });
        });

    })();

    // ── Student-added blocks palette ───────────────────────────────────────
    (function() {
        var container = document.getElementById('reflsub-student-blocks');
        var palette   = document.querySelector('.reflsub-student-palette');
        var hidden    = document.getElementById('reflsub-student-blocks-data');
        var form      = document.querySelector('.reflection-form');
        if (!container || !palette || !hidden || !form) return;

        // Seed from data-next-id so dynamic IDs don't collide with server-rendered blocks.
        var nextId = parseInt(container.dataset.nextId, 10) || 1;

        var LABELS = {
            text:  'Paragraph',
            image: 'Image(s)',
            video: 'Video URL',
            embed: 'Embed',
            pdf:   'PDF / File'
        };

        function buildBlock(type) {
            var id    = nextId++;
            var block = document.createElement('div');
            block.className    = 'reflsub-student-block';
            block.dataset.type = type;
            block.dataset.id   = id;

            var body = '';
            if (type === 'text') {
                body = '<textarea rows="5" class="reflsub-student-text" '
                     + 'placeholder="Write your paragraph…  (Leave a blank line between paragraphs.)"></textarea>';
            } else if (type === 'video') {
                body = '<input type="url" class="reflsub-student-video" '
                     + 'placeholder="https://www.youtube.com/watch?v=…">'
                     + '<p class="reflection-hint">Paste a YouTube or Vimeo URL — it will embed in your post.</p>';
            } else if (type === 'embed') {
                body = '<textarea rows="4" class="reflsub-student-embed" '
                     + 'placeholder="Paste your &lt;iframe&gt; embed code here — Kaltura, YouTube, Vimeo, etc."></textarea>'
                     + '<p class="reflection-hint">Only <code>&lt;iframe&gt;</code> tags are accepted; other HTML will be stripped.</p>';
            } else if (type === 'image') {
                body =
                    '<div class="reflsub-drop-zone">'
                        + '<div class="reflsub-drop-inner">'
                            + '<span class="reflsub-drop-icon" aria-hidden="true">🖼️</span>'
                            + '<p class="reflsub-drop-label">Drag &amp; drop images here</p>'
                            + '<p class="reflsub-drop-sub">or <label class="reflsub-drop-browse" for="reflsub-student-image-' + id + '">choose files</label></p>'
                        + '</div>'
                        + '<input type="file" id="reflsub-student-image-' + id + '" '
                        + 'name="reflsub_student_image_' + id + '[]" '
                        + 'accept="image/jpeg,image/png,image/gif,image/webp" multiple '
                        + 'class="reflsub-drop-input" aria-label="Upload images">'
                        + '<div class="reflsub-drop-previews"></div>'
                    + '</div>'
                    + '<p class="reflection-hint">JPEG, PNG, GIF, WebP — max 15 MB per file. Multiple images display as a gallery.</p>';
            } else if (type === 'pdf') {
                body = '<input type="file" name="reflsub_student_pdf_' + id + '" '
                     + 'accept=".pdf,application/pdf">'
                     + '<p class="reflection-hint">PDF only. Max 15 MB.</p>';
            }

            block.innerHTML =
                '<div class="reflsub-student-block-header">' +
                    '<span class="reflsub-student-block-label">' + (LABELS[type] || type) + '</span>' +
                    '<button type="button" class="reflsub-student-block-remove" aria-label="Remove this block">&times; Remove</button>' +
                '</div>' + body;

            // Remove handler is wired via event delegation on the container below,
            // so it works for both JS-built and server-rendered (edit-mode) blocks.

            return block;
        }

        // Delegated Remove-button handler — covers server-rendered blocks too.
        container.addEventListener('click', function(e) {
            var btn = e.target.closest('.reflsub-student-block-remove');
            if (!btn || !container.contains(btn)) return;
            var block = btn.closest('.reflsub-student-block');
            if (block) block.remove();
        });

        palette.querySelectorAll('.reflsub-student-add-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var block = buildBlock(btn.dataset.blockType);
                container.appendChild(block);
                // Wire up the drag-drop zone if this is an image block.
                var zone = block.querySelector('.reflsub-drop-zone');
                if (zone && typeof window.reflsubSetupDropZone === 'function') {
                    window.reflsubSetupDropZone(zone);
                }
                var first = block.querySelector('textarea, input[type="url"]');
                if (first) first.focus();
            });
        });

        // JSON.stringify that preserves non-ASCII as real UTF-8 instead of
        // \uXXXX escapes — keeps PHP's wp_unslash from eating the backslash.
        function jsonStringifyUtf8(data) {
            return JSON.stringify(data).replace(/\\u([0-9a-fA-F]{4})/g, function(_, hex) {
                return String.fromCharCode(parseInt(hex, 16));
            });
        }

        form.addEventListener('submit', function() {
            var blocks = [];
            container.querySelectorAll('.reflsub-student-block').forEach(function(el) {
                var type = el.dataset.type;
                var id   = parseInt(el.dataset.id, 10);
                var b    = { id: id, type: type };
                if (type === 'text') {
                    b.content = (el.querySelector('.reflsub-student-text')  || {}).value || '';
                } else if (type === 'video') {
                    b.content = (el.querySelector('.reflsub-student-video') || {}).value || '';
                } else if (type === 'embed') {
                    b.content = (el.querySelector('.reflsub-student-embed') || {}).value || '';
                }
                // image / pdf blocks carry no JSON content — files arrive via $_FILES
                blocks.push(b);
            });
            hidden.value = jsonStringifyUtf8(blocks);
        });
    })();

    // ── In-browser audio recorder (MediaRecorder) ────────────────────────────
    (function() {
        var recorders = document.querySelectorAll('.reflsub-audio-recorder');
        if (!recorders.length) return;
        var form = document.querySelector('.reflection-form');

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

        recorders.forEach(function(box) {
            var maxSeconds = parseInt(box.dataset.maxSeconds, 10) || 300;
            var recordBtn  = box.querySelector('.reflsub-audio-record');
            var stopBtn    = box.querySelector('.reflsub-audio-stop');
            var reBtn      = box.querySelector('.reflsub-audio-rerecord');
            var timerEl    = box.querySelector('.reflsub-audio-timer');
            var statusEl   = box.querySelector('.reflsub-audio-status');
            var playback   = box.querySelector('.reflsub-audio-playback');
            var input      = box.querySelector('.reflsub-audio-input');
            var existing   = box.querySelector('.reflsub-audio-existing');

            var mediaRecorder = null, chunks = [], stream = null;
            var ticker = null, elapsed = 0, objectUrl = null;

            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia ||
                typeof MediaRecorder === 'undefined') {
                statusEl.textContent = 'Audio recording isn’t supported in this browser. Try a recent Chrome, Firefox, Edge, or Safari.';
                recordBtn.disabled = true;
                return;
            }

            function resetTimer() {
                elapsed = 0;
                timerEl.textContent = '0:00';
            }

            function stopTracks() {
                if (stream) { stream.getTracks().forEach(function(t){ t.stop(); }); stream = null; }
            }

            function startTicker() {
                ticker = setInterval(function() {
                    elapsed++;
                    timerEl.textContent = fmt(elapsed) + ' / ' + fmt(maxSeconds);
                    if (elapsed >= maxSeconds) stopRecording();
                }, 1000);
            }

            function stopRecording() {
                if (mediaRecorder && mediaRecorder.state !== 'inactive') {
                    mediaRecorder.stop();
                }
            }

            recordBtn.addEventListener('click', function() {
                navigator.mediaDevices.getUserMedia({ audio: true }).then(function(s) {
                    stream = s;
                    chunks = [];
                    var picked = pickMime();
                    try {
                        mediaRecorder = picked ? new MediaRecorder(stream, { mimeType: picked.mime })
                                               : new MediaRecorder(stream);
                    } catch (e) {
                        mediaRecorder = new MediaRecorder(stream);
                    }

                    mediaRecorder.ondataavailable = function(ev) {
                        if (ev.data && ev.data.size > 0) chunks.push(ev.data);
                    };

                    mediaRecorder.onstop = function() {
                        clearInterval(ticker);
                        stopTracks();
                        var mime = mediaRecorder.mimeType || (picked ? picked.mime : 'audio/webm');
                        var blob = new Blob(chunks, { type: mime });

                        if (objectUrl) URL.revokeObjectURL(objectUrl);
                        objectUrl = URL.createObjectURL(blob);
                        playback.src = objectUrl;
                        playback.hidden = false;

                        // Hand the blob to the file input via DataTransfer so it rides
                        // the normal multipart submit as $_FILES['section_audio'].
                        var file = new File([blob], 'recording.' + extFor(mime), { type: mime });
                        var dt = new DataTransfer();
                        dt.items.add(file);
                        input.files = dt.files;

                        // A fresh recording supersedes any kept recording from edit mode.
                        if (existing) existing.style.display = 'none';

                        recordBtn.hidden = true;
                        stopBtn.hidden   = true;
                        reBtn.hidden     = false;
                        var kb = Math.round(blob.size / 1024);
                        statusEl.textContent = 'Recorded ' + fmt(elapsed) + ' (' +
                            (kb > 1024 ? (kb/1024).toFixed(1) + ' MB' : kb + ' KB') +
                            '). Play it back above, or re-record.';
                    };

                    resetTimer();
                    mediaRecorder.start();
                    startTicker();
                    recordBtn.hidden = true;
                    stopBtn.hidden   = false;
                    reBtn.hidden     = true;
                    playback.hidden  = true;
                    statusEl.textContent = 'Recording… click Stop when you’re done.';
                }).catch(function(err) {
                    statusEl.textContent = (err && err.name === 'NotAllowedError')
                        ? 'Microphone access was blocked. Allow it in your browser’s address-bar permissions, then try again.'
                        : 'Could not start recording: ' + (err && err.message ? err.message : 'unknown error') + '.';
                });
            });

            stopBtn.addEventListener('click', stopRecording);

            reBtn.addEventListener('click', function() {
                // Clear the captured recording and return to the initial state.
                if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
                playback.hidden = true;
                playback.removeAttribute('src');
                input.value = '';
                try { input.files = new DataTransfer().files; } catch (e) {}
                resetTimer();
                reBtn.hidden     = true;
                recordBtn.hidden = false;
                statusEl.textContent = 'Click Record and allow microphone access. Maximum length ' + fmt(maxSeconds) + '.';
            });
        });

        // Required-recording guard at submit time (native `required` can't see a
        // JS-assigned file input).
        if (form) {
            form.addEventListener('submit', function(e) {
                for (var i = 0; i !== recorders.length; i++) {
                    var box = recorders[i];
                    if (box.dataset.required !== '1') continue;
                    var input = box.querySelector('.reflsub-audio-input');
                    var kept  = box.querySelector('input[name="reflsub_keep_audio_id"]');
                    var keptVisible = kept && box.querySelector('.reflsub-audio-existing') &&
                                      box.querySelector('.reflsub-audio-existing').style.display !== 'none';
                    if ((!input.files || !input.files.length) && !keptVisible) {
                        e.preventDefault();
                        var status = box.querySelector('.reflsub-audio-status');
                        if (status) status.textContent = 'An audio recording is required before you can submit.';
                        box.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        return;
                    }
                }
            });
        }
    })();
