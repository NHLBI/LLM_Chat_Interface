'use strict';

var chatContainer;
var printChatTitle;
var searchingIndicator;
var chatTitlesContainer;
var popup;
var originalMessagePlaceholder = '';
var toastContainerEl = null;

function ensureToastContainer() {
    if (toastContainerEl && toastContainerEl.length) {
        return toastContainerEl;
    }
    toastContainerEl = $('<div class="toast-container" aria-live="polite" aria-atomic="true"></div>');
    $('body').append(toastContainerEl);
    return toastContainerEl;
}

function showToastMessage(message, options) {
    if (!message) {
        return;
    }
    var opts = options || {};
    var container = ensureToastContainer();
    var toast = $('<div class="toast-message" role="status"></div>');
    toast.text(message);
    container.append(toast);

    requestAnimationFrame(function () {
        toast.addClass('is-visible');
    });

    var duration = Number.isFinite(opts.duration) && opts.duration > 0 ? opts.duration : 4200;
    setTimeout(function () {
        toast.removeClass('is-visible');
        setTimeout(function () {
            toast.remove();
        }, 250);
    }, duration);
}

window.showToastMessage = showToastMessage;

var scrollTimeout;

// Build a safe, app-aware asset path: respects window.APP_PATH or <meta name="app-path">
function old_assetPath(rel) {
    var base = (window.APP_PATH ||
               (document.head.querySelector('meta[name="app-path"]') &&
                document.head.querySelector('meta[name="app-path"]').content) || '').replace(/\/+$/, '');
    rel = String(rel || '').replace(/^\/+/, '');
    // If base is set (e.g., "/chatdev"), generate "/chatdev/<rel>"; else return "/<rel>"
    return (base ? base : '') + '/' + rel;
}

function assetPath(rel) {
  var relClean = String(rel || '').replace(/^\/+/, '');
  var app = (typeof window.application_path === 'string' ? window.application_path : '').trim();
  app = app.replace(/^\/+|\/+$/g, ''); // strip leading/trailing slashes
  return '/' + (app ? app + '/' : '') + relClean;
}


// Canonical icon filenames (no leading "images/")
var ICONS = {
  pdf:   'images/icon_pdf.svg',
  doc:   'images/icon_docx.svg',
  docx:  'images/icon_docx.svg',
  rtf:   'images/icon_rtf.svg',
  txt:   'images/icon_txt.svg',     // fallback to docx if you don't have this
  md:    'images/icon_txt.svg',      // fallback to docx if you don't have this
  json:  'images/icon_xlsx.svg',    // fallback to csv/docx if you don't have this
  csv:   'images/icon_csv.svg',
  xls:   'images/icon_xlsx.svg',
  xlsx:  'images/icon_xlsx.svg',
  ppt:   'images/icon_pptx.svg',
  pptx:  'images/icon_pptx.svg',
  image: 'images/icon_image.svg',
  audio: 'images/icon_audio.svg',
  video: 'images/icon_video.svg',
  file:  'images/icon_file.svg'
};

// Map common MIME types to canonical keys used above
var MIME_ALIAS = {
  'application/pdf': 'pdf',
  'application/msword': 'doc',
  'application/rtf': 'rtf',
  'text/plain': 'txt',
  'text/markdown': 'md',
  'application/json': 'json',
  'text/csv': 'csv',
  'application/vnd.ms-excel': 'xls',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'xlsx',
  'application/vnd.ms-powerpoint': 'ppt',
  'application/vnd.openxmlformats-officedocument.presentationml.presentation': 'pptx',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'docx'
};

function waitForImagesToLoad(container, callback) {
    var images = container.find('img');
    var remaining = images.length;

    if (remaining === 0) {
        callback();
        return;
    }

    images.each(function () {
        if (this.complete) {
            remaining -= 1;
            if (remaining === 0) {
                callback();
            }
        } else {
            $(this).on('load error', function () {
                remaining -= 1;
                if (remaining === 0) {
                    callback();
                }
            });
        }
    });
}

function debounceScroll() {
    clearTimeout(scrollTimeout);
    scrollTimeout = setTimeout(scrollToBottom, 100);
}

function scrollToBottom() {
    if (!chatContainer || !chatContainer.length) {
        return;
    }
    chatContainer.scrollTop(chatContainer.prop('scrollHeight'));
}

function formatCodeBlocks(reply) {
    if (typeof reply !== 'string') {
        return '';
    }

    reply = reply
        .replace(/\\left\s*\[/g, '\\left.')
        .replace(/\\right\s*\]/g, '\\right|')
        .replace(/E\[(.*?)\]/g, 'E($1)')
        .replace(/\\\[([^\]]+)\\\]/g, function (_, latex) {
            return '<div class="math-block">$$ ' + latex + ' $$</div>';
        })
        .replace(/\\\(([^)]+)\\\)/g, function (_, latex) {
            return '<span class="math-inline">$ ' + latex + ' $</span>';
        });

    var codeBlocks = [];
    reply = reply.replace(/```(\w*)\n([\s\S]*?)```/g, function (_, lang, code) {
        var languageClass = lang ? 'language-' + lang : 'plaintext';
        var sanitized = sanitizeString(code);
        var html =
            '<div class="code-block">' +
            '<div class="language-label">' + (lang || 'code') + '</div>' +
            '<button class="copy-button" title="Copy Code" onclick="copyToClipboard(this)">' +
            '<span style="font-size:12px;">Copy Code</span>' +
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">' +
            '<path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/>' +
            '</svg>' +
            '</button>' +
            '<pre><code class="' + languageClass + '">' + sanitized + '</code></pre>' +
            '</div>';

        codeBlocks.push(html);
        return '@@CB_' + (codeBlocks.length - 1) + '@@';
    });

    reply = marked.parse(reply, { sanitize: false, breaks: false });

    var wrapper = document.createElement('div');
    wrapper.innerHTML = reply;
    wrapper.querySelectorAll('table').forEach(function (table) {
        var container = document.createElement('div');
        container.className = 'markdown-table-wrapper';
        if (table.parentNode) {
            table.parentNode.insertBefore(container, table);
            container.appendChild(table);
        }
    });
    reply = wrapper.innerHTML;

    codeBlocks.forEach(function (html, index) {
        reply = reply.replace('@@CB_' + index + '@@', html);
    });

    return reply;
}

function ensureReplyControls(messageElement) {
    var controls = messageElement.find('.reply-controls');
    if (!controls.length) {
        controls = $('<div class="reply-controls"></div>').css({
            display: 'flex',
            flexWrap: 'wrap',
            gap: '8px',
            marginTop: '8px'
        });
        messageElement.append(controls);
    }
    return controls;
}

function addCopyButton(messageElement, rawMessageContent) {
    var copyButton = $('<button class="copy-chat-button" title="Copy Raw Reply" aria-label="Copy the current reply to clipboard"><span style="font-size:12px;">Copy Raw Reply</span><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"></path></svg></button>');
    copyButton.css({
        display: 'inline-flex',
        alignItems: 'center',
        gap: '6px'
    });

    var controlsWrapper = ensureReplyControls(messageElement);
    controlsWrapper.append(copyButton);

    copyButton.on('click', function () {
        navigator.clipboard.writeText(rawMessageContent || '').then(function () {
            var popup = $('<span class="copied-chat-popup show">Copied!</span>').css({
                marginLeft: '12px',
                fontSize: '12px'
            });
            copyButton.after(popup);
            setTimeout(function () {
                popup.remove();
            }, 2000);
        }).catch(function (err) {
            console.error('Could not copy text:', err);
        });
    });
}

function resolveDocumentIconPath(docType, name) {
    var t = (docType || '').toLowerCase().trim();
    var ext = '';
    if (name) {
        var cleanedName = String(name).trim().toLowerCase();
        var m = cleanedName.match(/\.([a-z0-9]+)$/);
        if (m) ext = m[1];
    }

    if (t.indexOf(';') !== -1) {
        t = t.split(';')[0].trim();
    }

    // Prefer explicit MIME mapping
    if (t && MIME_ALIAS[t]) {
        return assetPath(ICONS[MIME_ALIAS[t]] || ICONS.file);
    }

    // Generic media groups
    if (t.indexOf('image/') === 0) return assetPath(ICONS.image);
    if (t.indexOf('audio/') === 0) return assetPath(ICONS.audio);
    if (t.indexOf('video/') === 0) return assetPath(ICONS.video);

    // Extension mapping
    if (ext && ICONS[ext]) return assetPath(ICONS[ext]);

    // Fallback
    return assetPath(ICONS.file);
}

function openImagePreview(imagePayload, trigger) {
    if (!imagePayload) {
        return;
    }

    var src = imagePayload.src || imagePayload.document_content || imagePayload.document_text || '';
    var $trigger = null;
    var focusTarget = null;

    if (window.jQuery && trigger) {
        if (trigger instanceof window.jQuery) {
            $trigger = trigger;
        } else {
            $trigger = window.jQuery(trigger);
        }
        if ($trigger && !$trigger.length) {
            $trigger = null;
        }
    }

    if ($trigger && $trigger.length) {
        focusTarget = $trigger.get(0);
        $trigger.addClass('message-attachment--loading');
        $trigger.attr('aria-busy', 'true');
    } else if (trigger && trigger.nodeType === 1) {
        focusTarget = trigger;
    }

    try {
        if (typeof window.showImagePreviewModal === 'function' && src) {
            window._imagePreviewReturnFocus = focusTarget || null;
            window.showImagePreviewModal({
                name: imagePayload.name || imagePayload.document_name || 'Image Preview',
                type: imagePayload.type || imagePayload.document_type || '',
                src: src,
                document_id: imagePayload.document_id || imagePayload.id || null
            });
            return;
        }

        if (imagePayload.document_id) {
            openDocumentExcerpt(imagePayload.document_id, trigger);
        } else {
            alert('Unable to display an image preview for this attachment.');
        }
    } finally {
        if ($trigger && $trigger.length) {
            $trigger.removeClass('message-attachment--loading');
            $trigger.removeAttr('aria-busy');
        }
    }
}

function openDocumentExcerpt(docId, trigger) {
    if (!docId) {
        return;
    }

    var $trigger = null;
    if (window.jQuery && trigger) {
        if (trigger instanceof window.jQuery) {
            $trigger = trigger;
        } else {
            $trigger = window.jQuery(trigger);
        }
        if ($trigger && !$trigger.length) {
            $trigger = null;
        }
    }

    if ($trigger) {
        $trigger.addClass('message-attachment--loading');
        $trigger.attr('aria-busy', 'true');
    }

    window.jQuery.ajax({
        url: 'document_excerpt.php',
        method: 'GET',
        dataType: 'json',
        data: { document_id: docId },
        success: function (response) {
            if (!response || response.ok !== true || !response.document) {
                alert('Unable to load the document preview at this time.');
                return;
            }

            if (typeof window.showDocumentExcerptModal === 'function') {
                var focusTarget = null;
                if ($trigger && $trigger.length) {
                    focusTarget = $trigger.get(0);
                } else if (trigger && trigger.nodeType === 1) {
                    focusTarget = trigger;
                }
                window._documentExcerptReturnFocus = focusTarget;
                window.showDocumentExcerptModal(response.document);
            } else {
                console.warn('showDocumentExcerptModal is not available on the window object.');
            }
        },
        error: function (xhr) {
            var message = 'Unable to load the document preview.';
            if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                message = xhr.responseJSON.message;
            }
            alert(message);
        },
        complete: function () {
            if ($trigger) {
                $trigger.removeClass('message-attachment--loading');
                $trigger.removeAttr('aria-busy');
            }
        }
    });
}

function openRagCitationPreview(exchangeId, docId, chunkIndex, trigger) {
    if (!exchangeId || !docId) {
        return;
    }

    var $trigger = null;
    if (window.jQuery && trigger) {
        $trigger = trigger instanceof window.jQuery ? trigger : window.jQuery(trigger);
        if ($trigger && !$trigger.length) {
            $trigger = null;
        }
    }

    if ($trigger) {
        $trigger.addClass('message-attachment--loading');
        $trigger.attr('aria-busy', 'true');
    }

    window.jQuery.ajax({
        url: resolveAppPath('rag_citation_preview.php'),
        method: 'GET',
        dataType: 'json',
        data: {
            exchange_id: exchangeId,
            document_id: docId,
            chunk_index: typeof chunkIndex === 'number' ? chunkIndex : ''
        },
        success: function (response) {
            if (!response || response.ok !== true || !response.citation) {
                alert('Unable to load the retrieved chunk preview at this time.');
                return;
            }

            if (typeof window.showDocumentExcerptModal === 'function') {
                var focusTarget = null;
                if ($trigger && $trigger.length) {
                    focusTarget = $trigger.get(0);
                } else if (trigger && trigger.nodeType === 1) {
                    focusTarget = trigger;
                }
                window._documentExcerptReturnFocus = focusTarget;
                window.showDocumentExcerptModal(response.citation);
            }
        },
        error: function (xhr) {
            var message = 'Unable to load the retrieved chunk preview.';
            if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                message = xhr.responseJSON.message;
            }
            alert(message);
        },
        complete: function () {
            if ($trigger) {
                $trigger.removeClass('message-attachment--loading');
                $trigger.removeAttr('aria-busy');
            }
        }
    });
}

function renderMessageAttachments(messageElement, documents) {
    if (!messageElement || !messageElement.length) {
        return;
    }

    var normalized = [];
    if (Array.isArray(documents)) {
        documents.forEach(function (doc) {
            if (doc && typeof doc === 'object') {
                normalized.push(doc);
            }
        });
    } else if (typeof documents === 'object' && documents !== null) {
        Object.keys(documents).forEach(function (key) {
            var doc = documents[key];
            if (doc && typeof doc === 'object') {
                var entry = $.extend({}, doc);
                if (entry.document_id === undefined) {
                    var parsedId = parseInt(key, 10);
                    if (!Number.isNaN(parsedId)) {
                        entry.document_id = parsedId;
                    }
                }
                normalized.push(entry);
            }
        });
    }

    if (!normalized.length) {
        return;
    }

    var docMap = (typeof chatId !== 'undefined' && window.chatDocumentsByChatId)
        ? (window.chatDocumentsByChatId[chatId] || null)
        : null;
    if (docMap) {
        normalized = normalized.map(function (doc) {
            if (!doc || typeof doc !== 'object') {
                return doc;
            }
            var docId = doc.document_id || doc.id;
            if (docId && docMap[docId]) {
                return Object.assign({}, docMap[docId], doc);
            }
            return doc;
        });
    }

    var container = $('<div class="message-attachments" role="list"></div>');

    normalized.forEach(function (doc) {
        if (!doc || typeof doc !== 'object') {
            return;
        }

        var docId = doc.document_id || doc.id;
        var docName = doc.document_name || doc.name || 'Document';
        var docType = (doc.document_type || doc.type || '').toLowerCase();
        var docSource = (doc.document_source || doc.source || '').toLowerCase();
        var docContent = doc.document_text || doc.document_content || '';
        var docDeleted = doc.document_deleted === 1 || doc.document_deleted === '1';
        var isImage = docType.indexOf('image/') === 0;
        var isReady = !docDeleted && (isImage || doc.document_ready === true || doc.document_ready === 1 || doc.document_ready === '1' || docSource === 'inline' || docSource === 'paste' || docSource === 'image');
        var wasEnabled = true;
        if (doc.was_enabled !== undefined && doc.was_enabled !== null) {
            wasEnabled = doc.was_enabled === true || doc.was_enabled === 1 || doc.was_enabled === '1';
        }

        var chipClass = isImage ? 'message-attachment message-attachment--image' : 'message-attachment message-attachment--document';
        var chip = $('<button type="button" class="' + chipClass + '" role="listitem"></button>');
        var iconPath = resolveDocumentIconPath(docType, docName);

        var icon = $('<img>', {
          src: iconPath,
          alt: '',
          class: 'message-attachment__icon',
          'aria-hidden': 'true'
        }).on('error', function () {
          if (this.dataset.fallback) return; // avoid loops
          this.dataset.fallback = '1';
          this.src = assetPath(ICONS.file);
        });

        var displayName = wasEnabled ? docName : docName + ' (disabled)';
        var nameSpan = $('<span class="message-attachment__name"></span>').text(displayName);
        chip.append(icon, nameSpan);

        if (docDeleted) {
            chip.addClass('message-attachment--removed');
            chip.attr('aria-label', docName + ' (removed)');
            chip.prop('disabled', true);
        } else if (!isReady) {
            chip.addClass('message-attachment--pending');
            chip.prop('disabled', true);
            var status = $('<span class="message-attachment__status"></span>').text('Processing…');
            chip.append(status);
        } else if (docId) {
            if (isImage && docContent) {
                chip.attr('aria-label', 'Open ' + docName + ' image preview');
                (function (payload) {
                    chip.on('click keypress', function (event) {
                        if (event.type === 'keypress' && event.key !== 'Enter' && event.key !== ' ') {
                            return;
                        }
                        event.preventDefault();
                        openImagePreview(payload, chip);
                    });
                })({
                    name: docName,
                    type: docType,
                    src: docContent,
                    document_id: docId
                });
            } else {
                chip.attr('aria-label', 'Open ' + displayName + ' excerpt');
                chip.on('click keypress', function (event) {
                    if (event.type === 'keypress' && event.key !== 'Enter' && event.key !== ' ') {
                        return;
                    }
                    event.preventDefault();
                    openDocumentExcerpt(docId, chip);
                });
            }
        } else {
            chip.prop('disabled', true);
        }

        if (!wasEnabled && !docDeleted) {
            chip.addClass('message-attachment--disabled');
        }

        if (docSource) {
            chip.attr('title', docSource);
        }

        container.append(chip);
    });

    if (!container.children().length) {
        return;
    }

    var existing = messageElement.find('.message-attachments');
    if (existing.length) {
        existing.remove();
    }

    messageElement.append(container);
}

function buildCitationSpanHtml(labelText, citation, exchangeId) {
    var span = document.createElement('span');
    span.className = 'rag-inline-citation';
    span.setAttribute('role', 'button');
    span.setAttribute('tabindex', '0');
    span.textContent = labelText;
    var docId = citation.document_id || citation.id || '';
    if (docId) {
        span.dataset.documentId = docId;
    }
    if (exchangeId) {
        span.dataset.exchangeId = exchangeId;
    } else if (citation.exchange_id) {
        span.dataset.exchangeId = citation.exchange_id;
    }
    if (citation.chunk_index !== undefined && citation.chunk_index !== null) {
        span.dataset.chunkIndex = citation.chunk_index;
    }
    var wrapper = document.createElement('div');
    wrapper.appendChild(span);
    return wrapper.innerHTML;
}

function normalizeDocName(value) {
    if (typeof value !== 'string') {
        return '';
    }
    var trimmed = value.trim();
    if (!trimmed) {
        return '';
    }
    var clean = trimmed.replace(/^\/*/, '').replace(/\\/g, '/');
    var segments = clean.split('/');
    return segments[segments.length - 1].toLowerCase();
}

function parseCitationLabel(labelText) {
    if (typeof labelText !== 'string' || !labelText.length) {
        return {};
    }
    var inner = labelText.trim();
    if (inner.charAt(0) === '【' && inner.charAt(inner.length - 1) === '】') {
        inner = inner.slice(1, -1);
    }
    inner = inner.trim();
    if (!inner) {
        return {};
    }
    var parts = inner.split(',');
    var docName = parts.shift();
    docName = docName ? docName.trim() : '';
    var normalizedDoc = normalizeDocName(docName);
    var chunkIndex = null;
    parts.forEach(function (part) {
        if (chunkIndex !== null) {
            return;
        }
        var match = part.match(/chunk\s+(\d+)/i);
        if (match) {
            chunkIndex = parseInt(match[1], 10);
        }
    });
    return {
        documentName: docName || null,
        normalizedDocumentName: normalizedDoc || null,
        chunkIndex: Number.isNaN(chunkIndex) ? null : chunkIndex
    };
}

function buildCitationKey(parsed) {
    if (!parsed || !parsed.normalizedDocumentName) {
        return null;
    }
    if (parsed.chunkIndex === null || typeof parsed.chunkIndex === 'undefined') {
        return null;
    }
    return parsed.normalizedDocumentName + '|' + String(parsed.chunkIndex);
}

function createCitationLookup(citations) {
    var map = Object.create(null);
    var pool = [];
    (citations || []).forEach(function (entry) {
        if (!entry || typeof entry !== 'object') {
            return;
        }
        pool.push(entry);
        var docName = normalizeDocName(entry.filename || entry.name || entry.title || '');
        if (!docName) {
            return;
        }
        var chunkValue = entry.chunk_index;
        if (chunkValue === undefined || chunkValue === null) {
            return;
        }
        var chunkNumber = typeof chunkValue === 'number' ? chunkValue : parseInt(chunkValue, 10);
        if (Number.isNaN(chunkNumber)) {
            return;
        }
        var key = docName + '|' + chunkNumber;
        if (!Object.prototype.hasOwnProperty.call(map, key)) {
            map[key] = entry;
        }
    });
    return {
        keyed: map,
        pool: pool
    };
}

function selectCitationForLabel(labelText, pool, parsedLabel) {
    if (!Array.isArray(pool) || !pool.length) {
        return null;
    }
    var parsed = parsedLabel || parseCitationLabel(labelText);
    var candidates = [
        function exactDocChunk(entry) {
            if (parsed.normalizedDocumentName && parsed.chunkIndex !== null) {
                var name = normalizeDocName(entry.filename || entry.name || entry.title || '');
                var chunk = entry.chunk_index;
                var chunkNumber = typeof chunk === 'number' ? chunk : parseInt(chunk, 10);
                if (!Number.isNaN(chunkNumber) && name === parsed.normalizedDocumentName && chunkNumber === parsed.chunkIndex) {
                    return true;
                }
            }
            return false;
        },
        function chunkOnly(entry) {
            if (parsed.chunkIndex !== null) {
                var chunk = entry.chunk_index;
                var chunkNumber = typeof chunk === 'number' ? chunk : parseInt(chunk, 10);
                if (!Number.isNaN(chunkNumber) && chunkNumber === parsed.chunkIndex) {
                    return true;
                }
            }
            return false;
        },
        function docOnly(entry) {
            if (parsed.normalizedDocumentName) {
                var name = normalizeDocName(entry.filename || entry.name || entry.title || '');
                if (name === parsed.normalizedDocumentName) {
                    return true;
                }
            }
            return false;
        },
        function anyEntry() {
            return true;
        }
    ];

    for (var i = 0; i < candidates.length; i++) {
        for (var j = 0; j < pool.length; j++) {
            if (candidates[i](pool[j])) {
                return pool.splice(j, 1)[0];
            }
        }
    }
    return null;
}

function injectInlineCitationHtml(html, citations, exchangeId) {
    if (typeof html !== 'string' || !html.length) {
        return html;
    }
    if (!Array.isArray(citations) || !citations.length) {
        return html;
    }
    var lookup = createCitationLookup(citations);
    var keyed = lookup.keyed || Object.create(null);
    var remaining = Array.isArray(lookup.pool) ? lookup.pool.slice() : citations.slice();
    var labelCache = Object.create(null);
    return html.replace(/【[^【】]+】/g, function (match) {
        if (Object.prototype.hasOwnProperty.call(labelCache, match)) {
            return labelCache[match];
        }
        var parsed = parseCitationLabel(match);
        var labelKey = buildCitationKey(parsed);
        var citation = null;
        if (labelKey && Object.prototype.hasOwnProperty.call(keyed, labelKey)) {
            citation = keyed[labelKey];
        }
        if (!citation) {
            citation = selectCitationForLabel(match, remaining, parsed);
        }
        if (!citation) {
            return match;
        }
        var spanHtml = buildCitationSpanHtml(match, citation, exchangeId);
        labelCache[match] = spanHtml;
        return spanHtml;
    });
}

function applyInlineRagCitationLinks(messageElement, citations, exchangeId) {
    if (!messageElement || !messageElement.length) {
        return false;
    }
    if (!Array.isArray(citations) || !citations.length) {
        return false;
    }

    var currentHtml = messageElement.html();
    var updatedHtml = injectInlineCitationHtml(currentHtml, citations, exchangeId);
    if (updatedHtml !== currentHtml) {
        messageElement.html(updatedHtml);
        return true;
    }
    return false;
}

function decorateInlineRagCitations(messageElement, citations, exchangeId) {
    if (!messageElement || !messageElement.length) {
        return;
    }
    var applied = false;
    if (Array.isArray(citations) && citations.length) {
        applied = applyInlineRagCitationLinks(messageElement, citations, exchangeId);
        if (applied) {
            return;
        }
    }
    if (!exchangeId || !window.jQuery) {
        return;
    }
    window.ragCitationsUnavailable = window.ragCitationsUnavailable || false;
    window.ragCitationsNotFound = window.ragCitationsNotFound || {};
    if (window.ragCitationsUnavailable) {
        return;
    }
    if (window.ragCitationsNotFound[exchangeId]) {
        return;
    }
    window.jQuery
        .getJSON(resolveAppPath('rag_citations.php'), { exchange_id: exchangeId })
        .done(function (resp) {
            if (resp && resp.ok && Array.isArray(resp.citations) && resp.citations.length) {
                applyInlineRagCitationLinks(messageElement, resp.citations, exchangeId);
            }
        })
        .fail(function (xhr) {
            if (xhr && xhr.status === 404) {
                window.ragCitationsNotFound[exchangeId] = true;
            }
            // silently ignore; citations remain plain text
        });
}

window.jQuery(document).on('click keypress', '.rag-inline-citation', function (event) {
    if (event.type === 'keypress' && event.key !== 'Enter' && event.key !== ' ') {
        return;
    }
    event.preventDefault();
    var $target = window.jQuery(this);
    var docId = $target.data('documentId');
    var chunkIndex = $target.data('chunkIndex');
    var exchangeId = $target.data('exchangeId');
    openRagCitationPreview(exchangeId, docId, chunkIndex, this);
});
