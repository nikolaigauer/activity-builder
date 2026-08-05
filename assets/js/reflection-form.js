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

        // ── Auto-growing textareas ─────────────────────────────────────────────
        // A fixed 6-row box is fine for a paragraph and hostile for an essay, so
        // grow to fit the content — but only up to a ceiling. An unbounded
        // textarea pushes the Submit button several screens down, which is its
        // own usability problem; past the ceiling the field scrolls internally.
        // A delegated listener plus an init pass means dynamically added student
        // blocks are covered without extra wiring.
        var AUTOGROW_MAX_FRACTION = 0.6; // of viewport height

        function reflsubAutoGrow(ta) {
            var max = Math.round(window.innerHeight * AUTOGROW_MAX_FRACTION);
            ta.style.height = 'auto';
            // box-sizing is border-box, so scrollHeight (content + padding) needs
            // the borders added back to land on the correct border-box height.
            var border = ta.offsetHeight - ta.clientHeight;
            var target = ta.scrollHeight + border;
            if ( target > max ) {
                ta.style.height    = max + 'px';
                ta.style.overflowY = 'auto';
            } else {
                ta.style.height    = target + 'px';
                ta.style.overflowY = 'hidden';
            }
        }

        function reflsubAutoGrowAll() {
            document.querySelectorAll('.reflection-form textarea').forEach(reflsubAutoGrow);
        }

        document.addEventListener('input', function(e) {
            if ( e.target && e.target.tagName === 'TEXTAREA'
                 && e.target.closest && e.target.closest('.reflection-form') ) {
                reflsubAutoGrow(e.target);
            }
        });
        reflsubAutoGrowAll();

        // ── Word counters ──────────────────────────────────────────────────────
        // Shown for every prompt field; the "/ limit" half only appears when the
        // instructor set one. A running count is reassuring for long-form writing
        // even with no limit to measure against.
        document.querySelectorAll('.reflection-form textarea[data-counter-id]').forEach(function(ta) {
            var counter = document.getElementById(ta.dataset.counterId);
            if (!counter) return;
            var limit = parseInt(ta.dataset.wordLimit, 10) || 0;
            function update() {
                var trimmed = ta.value.trim();
                var words   = trimmed === '' ? 0 : trimmed.split(/\s+/).length;
                counter.textContent = limit
                    ? words + ' / ' + limit + ' words'
                    : words + ( words === 1 ? ' word' : ' words' );
                counter.style.color = ( limit && words > limit ) ? '#d63638' : '#646970';
            }
            ta.addEventListener('input', update);
            update();
        });

        // ── LocalStorage autosave ─────────────────────────────────────────────
        var form = document.querySelector('.reflection-form[data-page-id]');

        // The key is built from PHP-localized ids, NOT from the form element.
        // The success screen renders no form, and that is precisely the page where
        // the stale draft must be cleared — deriving the key from the form makes
        // the cleanup below a silent no-op there, so the previous entry's text gets
        // restored into the next one on resubmission-enabled pages.
        // Scoped to page AND user so drafts never leak between students on a
        // shared lab machine. The form dataset is a fallback for cached pages
        // served before the localized ids existed.
        var reflsubCfg = window.reflsubForm || {};
        var draftPage  = reflsubCfg.pageId || ( form && form.dataset.pageId );
        var draftUser  = reflsubCfg.userId || ( form && form.dataset.userId );
        var draftKey   = ( draftPage && draftUser )
            ? 'reflsub_draft_' + draftPage + '_' + draftUser
            : null;

        // Clean up any legacy un-scoped key left by earlier builds.
        if ( draftPage ) { try { localStorage.removeItem( 'reflsub_draft_' + draftPage ); } catch(e) {} }

        // States where the server now holds the text, so the local copy is stale
        // and must not be restored over it: a completed submit, a completed edit,
        // or a saved server-side draft.
        var REFLSUB_TERMINAL = /(?:reflection_submitted|reflection_updated|reflection_draft_saved)=1/;

        if ( draftKey ) {
            if ( REFLSUB_TERMINAL.test( window.location.search ) ) {
                // Runs whether or not a form is on the page — this is the fix.
                localStorage.removeItem( draftKey );
            } else if ( form ) {
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
                        // Restored text changes how tall each field needs to be.
                        reflsubAutoGrowAll();

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
                            reflsubAutoGrowAll();
                        });
                    }
                }
            }

            // Save on input, debounced 2 s. Attached in every state that HAS a form
            // — including just after a server-side draft save — so typing resumed on
            // the reloaded form keeps its local safety net. Guarded because the
            // success screen reaches this block with no form on the page.
            if ( form ) {
                var saveTimer;
                var reflsubSaveDraft = function () {
                    var data = {};
                    form.querySelectorAll('textarea, input[type="text"]').forEach(function(el) {
                        if ( el.name ) data[el.name] = el.value;
                    });
                    try { localStorage.setItem( draftKey, JSON.stringify(data) ); } catch(e) {}
                };
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
            pdf:   'PDF / File',
            audio: 'Audio'
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
            } else if (type === 'audio') {
                // Controls are injected by reflsubSetupAudioRecorder(); we only supply
                // the container + the (context-named) file input.
                body =
                    '<div class="reflsub-audio-recorder" data-max-seconds="300" data-required="0">'
                        + '<input type="file" class="reflsub-audio-input" name="reflsub_student_audio_' + id + '" accept="audio/*" hidden>'
                    + '</div>';
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
                // Wire up the audio recorder if this is an audio block.
                var recorder = block.querySelector('.reflsub-audio-recorder');
                if (recorder && typeof window.reflsubSetupAudioRecorder === 'function') {
                    window.reflsubSetupAudioRecorder(recorder);
                }
                var first = block.querySelector('textarea, input[type="url"]');
                if (first) first.focus();
            });
        });

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
                // image / pdf / audio blocks carry no JSON content — files arrive via $_FILES
                blocks.push(b);
            });
            hidden.value = JSON.stringify(blocks);
        });
    })();

    // ── Required-audio guard ─────────────────────────────────────────────────
    // The recorder widgets are wired up by assets/js/audio-recorder.js (shared).
    // Here we only enforce instructor-marked "required" audio sections at submit
    // time, since a native `required` attribute can't see a file input populated
    // via DataTransfer. Recorders are re-queried at submit so student-added audio
    // blocks are covered too (those are never required).
    (function() {
        var form = document.querySelector('.reflection-form');
        if (!form) return;
        form.addEventListener('submit', function(e) {
            var recorders = document.querySelectorAll('.reflsub-audio-recorder');
            for (var i = 0; i !== recorders.length; i++) {
                var box = recorders[i];
                if (box.dataset.required !== '1') continue;
                var input      = box.querySelector('.reflsub-audio-input');
                var existingEl = box.querySelector('.reflsub-audio-existing');
                var keptVisible = box.querySelector('input[name="reflsub_keep_audio_id"]') &&
                                  existingEl && existingEl.style.display !== 'none';
                if ((!input || !input.files || !input.files.length) && !keptVisible) {
                    e.preventDefault();
                    var status = box.querySelector('.reflsub-audio-status');
                    if (status) status.textContent = 'An audio recording is required before you can submit.';
                    box.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return;
                }
            }
        });
    })();
