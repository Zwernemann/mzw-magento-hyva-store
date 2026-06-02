/**
 * Conversational Commerce – Web Chat Widget (Vanilla JS / Hyvä-compatible)
 *
 * No RequireJS, no jQuery. Works with both Hyvä and Luma themes.
 * Customer login state is detected via:
 *   1. Hyvä's `private-content-loaded` window event
 *   2. Fallback: Magento sections API fetch (/customer/section/load)
 */
(function () {
    'use strict';

    function initCCChatWidget(config, element) {
        var cfg = JSON.parse(element.getAttribute('data-config') || '{}');

        var SESSION_KEY = 'cc_chat_session_' + (cfg.storeId || 0);
        var HISTORY_KEY = 'cc_chat_history_' + (cfg.storeId || 0);

        var fab       = element.querySelector('#cc-chat-fab');
        var backdrop  = element.querySelector('#cc-chat-backdrop');
        var modal     = element.querySelector('#cc-chat-modal');
        var messages  = element.querySelector('#cc-chat-messages');
        var typing    = element.querySelector('#cc-chat-typing');
        var form      = element.querySelector('#cc-chat-form');
        var input     = element.querySelector('#cc-message-input');
        var fileInput = element.querySelector('#cc-file-input');
        var preview   = element.querySelector('#cc-attachments-preview');
        var sessionEl = element.querySelector('#cc-session-id');
        var formKeyEl = element.querySelector('#cc-form-key');
        var sendBtn   = element.querySelector('#cc-send-btn');
        var statusTxt = element.querySelector('#cc-status-text');

        var isOpen     = false;
        var isLoading  = false;
        var isLoggedIn = false;
        var sessionId  = '';
        var pendingFiles = [];

        if (cfg.primaryColor) {
            element.style.setProperty('--cc-primary', cfg.primaryColor);
            element.style.setProperty('--cc-primary-dark', darkenHex(cfg.primaryColor, 20));
        }

        // ── Form key ────────────────────────────────────────────────────────
        function refreshFormKey() {
            var cookieMatch = document.cookie.match(/(?:^|;\s*)form_key=([^;]+)/);
            if (cookieMatch) {
                var key = decodeURIComponent(cookieMatch[1]);
                formKeyEl.value = key;
                return key;
            }
            var fromDom = document.querySelector('input[name="form_key"]:not(#cc-form-key)');
            if (fromDom) {
                formKeyEl.value = fromDom.value;
                return fromDom.value;
            }
            return '';
        }

        // ── Customer data (Hyvä + Luma compatible) ──────────────────────────
        function applyLoginState(customerInfo) {
            var loggedIn = !!(customerInfo && (customerInfo.fullname || customerInfo.firstname));
            isLoggedIn = loggedIn;
            if (loggedIn) {
                fab.classList.remove('cc-chat-fab--hidden');
                if (window.location.hash === '#cc-chat-open' && !isOpen) { openChat(); }
            } else {
                fab.classList.add('cc-chat-fab--hidden');
                if (isOpen) { closeChat(); }
            }
        }

        // Hyvä fires this event when private content sections are loaded
        window.addEventListener('private-content-loaded', function (e) {
            var data = e.detail && e.detail.data;
            applyLoginState(data && data.customer);
        });

        // Fallback: fetch sections directly (works in Luma and if Hyvä event already fired)
        fetch('/customer/section/load?sections=customer&update_section_id=false', {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.ok ? r.json() : null; })
          .then(function (data) { if (data) { applyLoginState(data.customer); } })
          .catch(function () {});

        // ── Open / close ─────────────────────────────────────────────────────
        function openChat() {
            if (!isLoggedIn) { return; }
            isOpen = true;
            refreshFormKey();
            backdrop.classList.add('cc-open');
            modal.classList.add('cc-open');
            modal.setAttribute('aria-hidden', 'false');
            var iconChat  = fab.querySelector('.cc-fab-icon-chat');
            var iconClose = fab.querySelector('.cc-fab-icon-close');
            if (iconChat)  { iconChat.style.display  = 'none'; }
            if (iconClose) { iconClose.style.display = ''; }
            input.focus();
        }

        function closeChat() {
            isOpen = false;
            backdrop.classList.remove('cc-open');
            modal.classList.remove('cc-open');
            modal.setAttribute('aria-hidden', 'true');
            var iconChat  = fab.querySelector('.cc-fab-icon-chat');
            var iconClose = fab.querySelector('.cc-fab-icon-close');
            if (iconChat)  { iconChat.style.display  = ''; }
            if (iconClose) { iconClose.style.display = 'none'; }
        }

        fab.addEventListener('click', function () { isOpen ? closeChat() : openChat(); });
        var closeBtn = element.querySelector('#cc-chat-close');
        if (closeBtn) { closeBtn.addEventListener('click', closeChat); }
        backdrop.addEventListener('click', closeChat);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isOpen) { closeChat(); }
        });

        // ── Render messages ──────────────────────────────────────────────────
        function renderMessage(role, text, fileNames, timestamp) {
            var isUser  = role === 'user';
            var timeStr = timestamp || currentTimeStr();
            var safeText = isUser
                ? escapeHtml(text).replace(/\n/g, '<br>')
                : sanitizeAiHtml(text);

            var fileTags = '';
            if (fileNames && fileNames.length) {
                fileTags = '<div class="cc-file-tags">'
                    + fileNames.map(function (n) {
                        return '<span class="cc-file-tag">📎 ' + escapeHtml(n) + '</span>';
                    }).join('') + '</div>';
            }

            var html = '<div class="cc-msg cc-msg-' + role + '">'
                + '<div class="cc-bubble">' + safeText + fileTags + '</div>'
                + '<span class="cc-msg-time">' + escapeHtml(timeStr) + '</span>'
                + '</div>';

            messages.insertAdjacentHTML('beforeend', html);
            scrollToBottom();
        }

        function scrollToBottom() {
            messages.scrollTop = messages.scrollHeight;
        }

        // ── File attachments ─────────────────────────────────────────────────
        fileInput.addEventListener('change', function () {
            Array.from(this.files || []).forEach(function (f) {
                pendingFiles.push({ file: f, name: f.name });
            });
            this.value = '';
            renderPreviews();
        });

        function renderPreviews() {
            preview.innerHTML = '';
            if (!pendingFiles.length) { preview.style.display = 'none'; return; }
            preview.style.display = '';
            pendingFiles.forEach(function (item, idx) {
                var badge = document.createElement('span');
                badge.className = 'cc-preview-badge';
                badge.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
                badge.appendChild(document.createTextNode(truncate(item.name, 22)));
                var removeBtn = document.createElement('button');
                removeBtn.className = 'cc-preview-remove';
                removeBtn.type = 'button';
                removeBtn.setAttribute('aria-label', 'Remove ' + item.name);
                removeBtn.textContent = '✕';
                (function (i) {
                    removeBtn.addEventListener('click', function () {
                        pendingFiles.splice(i, 1);
                        renderPreviews();
                    });
                }(idx));
                badge.appendChild(removeBtn);
                preview.appendChild(badge);
            });
        }

        // ── Send message ─────────────────────────────────────────────────────
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (isLoading || !isLoggedIn) { return; }

            var text = input.value.trim();
            if (!text && !pendingFiles.length) { return; }

            var fileNames = pendingFiles.map(function (i) { return i.name; });
            var history   = loadHistory();

            renderMessage('user', text, fileNames);
            history.push({ role: 'user', text: text, files: fileNames, time: currentTimeStr() });

            input.value = '';
            input.style.height = 'auto';

            var fd = new FormData();
            fd.append('form_key', refreshFormKey());
            fd.append('session_id', sessionId);
            fd.append('message', text);
            pendingFiles.forEach(function (item) {
                fd.append('attachments[]', item.file, item.name);
            });
            pendingFiles = [];
            renderPreviews();

            setLoading(true);

            fetch(cfg.sendUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    setLoading(false);
                    if (resp && (resp.response_html || resp.response)) {
                        var content = resp.response_html || resp.response;
                        renderMessage('ai', content);
                        history.push({ role: 'ai', text: content, time: currentTimeStr() });
                        saveHistory(history);
                        if (resp.session_id) { saveSession(resp.session_id); }
                    } else {
                        renderMessage('ai', 'Sorry, an unexpected error occurred.');
                    }
                    if (resp && resp.debug_blocks && resp.debug_blocks.length) {
                        window._ccDbgHandled = true;
                        resp.debug_blocks.forEach(function (block) { renderDebugBlock(block); });
                        scrollToBottom();
                    }
                })
                .catch(function (err) {
                    setLoading(false);
                    renderMessage('ai', 'Sorry, something went wrong. Please try again.');
                    console.error('[CC Chat]', err);
                });
        });

        // ── Textarea auto-resize + Enter to send ─────────────────────────────
        input.addEventListener('input', function () {
            this.style.height = 'auto';
            this.style.height = Math.min(Math.max(this.scrollHeight, 40), 120) + 'px';
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                form.dispatchEvent(new Event('submit', { cancelable: true }));
            }
        });

        // ── Loading state ────────────────────────────────────────────────────
        var STATUS_MESSAGES = [
            'Anfrage wird analysiert…',
            'Produktkatalog wird durchsucht…',
            'KI verarbeitet Ihre Anfrage…',
            'Bestellhistorie wird geprüft…',
            'Antwort wird aufbereitet…'
        ];
        var statusTimer = null;
        var statusIndex = 0;

        function startStatusCycle() {
            statusIndex = 0;
            statusTxt.textContent = STATUS_MESSAGES[0];
            statusTimer = setInterval(function () {
                statusIndex = Math.min(statusIndex + 1, STATUS_MESSAGES.length - 1);
                statusTxt.textContent = STATUS_MESSAGES[statusIndex];
            }, 5000);
        }

        function stopStatusCycle() {
            clearInterval(statusTimer);
            statusTimer = null;
            statusTxt.textContent = '';
        }

        function setLoading(state) {
            isLoading = state;
            sendBtn.disabled = state;
            input.disabled   = state;
            if (state) {
                typing.style.display = '';
                startStatusCycle();
                scrollToBottom();
            } else {
                stopStatusCycle();
                typing.style.display = 'none';
            }
        }

        // ── LLM Debug Block ──────────────────────────────────────────────────
        function renderDebugBlock(block) {
            var reqJson = JSON.stringify(block.request, null, 2);
            var resStr  = block.response;
            try { resStr = JSON.stringify(JSON.parse(resStr), null, 2); } catch (e) {}

            var html = '<div class="cc-dbg">'
                + '<div class="cc-dbg-title">&#x1F50D; LLM DEBUG &#x2014; ' + escapeHtml(block.title) + '</div>'
                + '<div class="cc-dbg-label"><span class="cc-dbg-chevron">&#x25B6;</span> Request (Payload)</div>'
                + '<pre class="cc-dbg-pre">' + escapeHtml(reqJson) + '</pre>'
                + '<div class="cc-dbg-label"><span class="cc-dbg-chevron">&#x25B6;</span> Response (HTTP Body)</div>'
                + '<pre class="cc-dbg-pre">' + escapeHtml(resStr) + '</pre>'
                + '</div>';
            messages.insertAdjacentHTML('beforeend', html);
        }

        messages.addEventListener('click', function (e) {
            var label = e.target.closest('.cc-dbg-label');
            if (!label) { return; }
            var pre     = label.nextElementSibling;
            var chevron = label.querySelector('.cc-dbg-chevron');
            if (!pre || !pre.classList.contains('cc-dbg-pre')) { return; }
            var open = pre.classList.contains('cc-dbg-open');
            pre.classList.toggle('cc-dbg-open', !open);
            if (chevron) { chevron.innerHTML = open ? '&#x25B6;' : '&#x25BC;'; }
        });

        // ── Session handling ─────────────────────────────────────────────────
        function loadSession() {
            try { sessionId = sessionStorage.getItem(SESSION_KEY) || ''; } catch (e) { sessionId = ''; }
            sessionEl.value = sessionId;
        }

        function saveSession(id) {
            sessionId = id;
            sessionEl.value = id;
            try { sessionStorage.setItem(SESSION_KEY, id); } catch (e) {}
        }

        function loadHistory() {
            try { return JSON.parse(sessionStorage.getItem(HISTORY_KEY) || '[]'); } catch (e) { return []; }
        }

        function saveHistory(history) {
            try { sessionStorage.setItem(HISTORY_KEY, JSON.stringify(history.slice(-40))); } catch (e) {}
        }

        // ── Init ─────────────────────────────────────────────────────────────
        function init() {
            loadSession();
            var history = loadHistory();
            if (history.length) {
                history.forEach(function (entry) {
                    renderMessage(entry.role, entry.text, entry.files || [], entry.time);
                });
            } else {
                renderMessage('ai', cfg.welcomeMessage || 'Hello! How can I help you today?');
            }
        }

        // ── Utilities ────────────────────────────────────────────────────────
        function escapeHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        var ALLOWED_TAGS = /^(p|br|strong|b|em|i|ul|ol|li|span|a|h[1-6]|blockquote|pre|code|hr|img|table|thead|tbody|tr|td|th|div)$/i;

        function sanitizeAiHtml(html) {
            if (!html) { return ''; }
            var div = document.createElement('div');
            div.innerHTML = html;
            sanitizeNode(div);
            return div.innerHTML;
        }

        function sanitizeNode(node) {
            Array.from(node.childNodes).forEach(function (child) {
                if (child.nodeType === 3) { return; }
                if (child.nodeType === 1) {
                    if (!ALLOWED_TAGS.test(child.tagName)) {
                        while (child.firstChild) { node.insertBefore(child.firstChild, child); }
                        node.removeChild(child);
                    } else {
                        var tag = child.tagName.toLowerCase();
                        Array.from(child.attributes || []).forEach(function (attr) {
                            if (tag === 'a'   && attr.name === 'href')  { return; }
                            if (tag === 'img' && attr.name === 'src')   { return; }
                            if (tag === 'img' && attr.name === 'alt')   { return; }
                            if (tag === 'img' && attr.name === 'width') { return; }
                            child.removeAttribute(attr.name);
                        });
                        if (tag === 'a') {
                            child.setAttribute('target', '_blank');
                            child.setAttribute('rel', 'noopener noreferrer');
                            var href = child.getAttribute('href') || '';
                            if (/^javascript:/i.test(href.trim())) { child.removeAttribute('href'); }
                        }
                        if (tag === 'img') {
                            var src = child.getAttribute('src') || '';
                            if (!/^(data:image\/|https:\/\/)/i.test(src.trim())) { child.removeAttribute('src'); }
                            child.setAttribute('style', 'max-width:160px;border-radius:6px;margin:4px 0;display:block;');
                        }
                        sanitizeNode(child);
                    }
                } else {
                    node.removeChild(child);
                }
            });
        }

        function currentTimeStr() {
            var d = new Date();
            return d.getHours().toString().padStart(2, '0') + ':' + d.getMinutes().toString().padStart(2, '0');
        }

        function truncate(str, max) {
            return str.length > max ? str.slice(0, max - 1) + '…' : str;
        }

        function darkenHex(hex, amount) {
            try {
                hex = hex.replace('#', '');
                if (hex.length === 3) { hex = hex.split('').map(function (c) { return c + c; }).join(''); }
                var r = Math.max(0, parseInt(hex.slice(0, 2), 16) - amount);
                var g = Math.max(0, parseInt(hex.slice(2, 4), 16) - amount);
                var b = Math.max(0, parseInt(hex.slice(4, 6), 16) - amount);
                return '#' + [r, g, b].map(function (n) { return n.toString(16).padStart(2, '0'); }).join('');
            } catch (e) { return '#' + hex; }
        }

        init();
    }

    // Auto-initialize on #cc-chat-root when script loads
    // (script is placed after the element in before.body.end, so it's already in DOM)
    var root = document.getElementById('cc-chat-root');
    if (root) {
        initCCChatWidget({}, root);
    }

    window.initCCChatWidget = initCCChatWidget;
}());
