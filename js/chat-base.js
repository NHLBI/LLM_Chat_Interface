'use strict';

var chatContainer;
var printChatTitle;
var searchingIndicator;
var chatTitlesContainer;
var popup;
var originalMessagePlaceholder = '';

var scrollTimeout;

var DOCUMENT_ICON_MAP = {
    pdf: 'images/icon_pdf.svg',
    doc: 'images/icon_docx.svg',
    docx: 'images/icon_docx.svg',
    ppt: 'images/icon_pptx.svg',
    pptx: 'images/icon_pptx.svg',
    xls: 'images/icon_xlsx.svg',
    xlsx: 'images/icon_xlsx.svg',
    csv: 'images/icon_csv.svg',
    json: 'images/icon_csv.svg'
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
    var lowered = (docType || '').toLowerCase();
    if (!lowered && name) {
        var extMatch = name.match(/\.([a-z0-9]+)$/i);
        if (extMatch) {
            lowered = extMatch[1].toLowerCase();
        }
    }

    if (lowered && DOCUMENT_ICON_MAP[lowered]) {
        return DOCUMENT_ICON_MAP[lowered];
    }

    if (lowered.indexOf('image/') === 0) {
        return 'images/icon_image.svg';
    }
    if (lowered.indexOf('audio/') === 0) {
        return 'images/icon_audio.svg';
    }
    if (lowered.indexOf('video/') === 0) {
        return 'images/icon_video.svg';
    }
    return 'images/icon_file.svg';
}

function openDocumentExcerpt(docId, trigger) {
    if (!docId) {
        return;
    }

    var modal = $('#documentExcerptModal');
    if (!modal.length) {
        return;
    }

    modal.attr('data-document-id', docId);
    modal.fadeIn(150);
    $('body').addClass('modal-open');

    fetch('document_excerpt.php?id=' + encodeURIComponent(docId), {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
        .then(function (response) {
            if (!response.ok) {
                throw new Error('Unable to load document excerpt');
            }
            return response.text();
        })
        .then(function (html) {
            modal.find('.document-excerpt__content').html(html);
        })
        .catch(function (err) {
            console.error(err);
            modal.find('.document-excerpt__content').text('Unable to load document excerpt.');
        });

    if (trigger && trigger.length) {
        modal.data('invoker', trigger);
    } else {
        modal.removeData('invoker');
    }
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

        if (isImage && docContent) {
            var imageWrapper = $('<div class="message-attachment message-attachment--image" role="listitem"></div>');
            var img = $('<img>', {
                src: docContent,
                alt: docName,
                class: 'message-attachment__image'
            });
            if (docDeleted) {
                imageWrapper.addClass('message-attachment--removed');
                imageWrapper.attr('aria-label', docName + ' (removed)');
            }
            imageWrapper.append(img);
            container.append(imageWrapper);
            return;
        }

        var chip = $('<button type="button" class="message-attachment message-attachment--document" role="listitem"></button>');
        var iconPath = resolveDocumentIconPath(docType, docName);
        var icon = $('<img>', {
            src: iconPath,
            alt: '',
            class: 'message-attachment__icon',
            'aria-hidden': 'true'
        });
        var nameSpan = $('<span class="message-attachment__name"></span>').text(docName);
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
            chip.attr('aria-label', 'Open ' + docName + ' excerpt');
            chip.on('click keypress', function (event) {
                if (event.type === 'keypress' && event.key !== 'Enter' && event.key !== ' ') {
                    return;
                }
                event.preventDefault();
                openDocumentExcerpt(docId, chip);
            });
        } else {
            chip.prop('disabled', true);
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
