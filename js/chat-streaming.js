'use strict';

var activeAssistantStream = null;

function normalizeStreamingPreview(text) {
    if (typeof text !== 'string' || text.length === 0) {
        return text;
    }

    var collapsed = text.replace(/([\p{L}\p{N}'’\-]+)(\s+\1)+$/iu, '$1');
    return collapsed;
}

function findDanglingCodeFence(text) {
    if (typeof text !== 'string' || text.indexOf('```') === -1) {
        return null;
    }

    var fenceRegex = /```([^\n]*)?/g;
    var match;
    var openFence = null;

    while ((match = fenceRegex.exec(text)) !== null) {
        if (openFence === null) {
            openFence = {
                language: (match[1] || '').trim(),
                index: match.index
            };
        } else {
            openFence = null;
        }
    }

    return openFence;
}

function prepareStreamingMarkdownPreview(text) {
    if (typeof text !== 'string') {
        return {
            textForFormatting: '',
            hasDanglingFence: false
        };
    }

    var prepared = text;
    var hasDanglingFence = false;
    // Ensure in-progress fenced code blocks still render by appending a temporary closing fence.
    var openFence = findDanglingCodeFence(prepared);

    if (openFence) {
        hasDanglingFence = true;
        if (!/\n$/.test(prepared)) {
            prepared += '\n';
        }
        prepared += '```';
    }

    return {
        textForFormatting: prepared,
        hasDanglingFence: hasDanglingFence
    };
}

function renderStreamingPreviewHtml(text) {
    if (typeof text !== 'string' || text.length === 0) {
        return '';
    }

    if (typeof formatCodeBlocks !== 'function') {
        if (typeof sanitizeString === 'function') {
            return '<p>' + sanitizeString(text) + '</p>';
        }
        return text;
    }

    var prepared = prepareStreamingMarkdownPreview(text);
    return formatCodeBlocks(prepared.textForFormatting);
}

function createStreamingAssistantMessage(deploymentKey, options) {
    options = options || {};
    var deploymentMeta = (typeof deployments !== 'undefined' && deploymentKey && deployments[deploymentKey]) ? deployments[deploymentKey] : null;
    var avatarSrc = deploymentMeta && deploymentMeta.image ? 'images/' + deploymentMeta.image : 'images/openai_logo.svg';
    var avatarAlt = deploymentMeta && deploymentMeta.image_alt ? deploymentMeta.image_alt : 'Assistant avatar';

    var messageElement = $('<div class="message assistant-message streaming"></div>');
    messageElement.prepend('<img src="' + avatarSrc + '" alt="' + avatarAlt + '" class="openai-icon">');

    //var contentElement = $('<div class="assistant-stream__content"></div>');
    var contentElement = $('<div class=""></div>');
    var cursor = $('<span class="assistant-stream__cursor" aria-hidden="true"></span>');
    contentElement.append(cursor);
    messageElement.append(contentElement);

    var controlsWrapper = ensureReplyControls(messageElement);
    var stopButton = $('<button type="button" class="copy-chat-button stream-stop-button"></button>').text('Stop');
    stopButton.hide();
    controlsWrapper.append(stopButton);

    chatContainer.append(messageElement);
    debounceScroll();

    return {
        messageElement: messageElement,
        contentElement: contentElement,
        cursor: cursor,
        controlsWrapper: controlsWrapper,
        controls: {
            stop: stopButton
        }
    };
}

function parseSseEventChunk(rawChunk) {
    var eventType = 'message';
    var dataLines = [];
    var lines = rawChunk.split('\n');
    for (var i = 0; i < lines.length; i++) {
        var line = lines[i];
        if (!line || line[0] === ':') {
            continue;
        }
        if (line.indexOf('event:') === 0) {
            eventType = line.slice(6).trim();
        } else if (line.indexOf('data:') === 0) {
            dataLines.push(line.slice(5).replace(/^\s+/, ''));
        }
    }
    var payloadText = dataLines.join('\n');
    var parsedData = null;
    if (payloadText) {
        try {
            parsedData = JSON.parse(payloadText);
        } catch (err) {
            parsedData = payloadText;
        }
    }
    return {
        type: eventType,
        data: parsedData,
        raw: payloadText
    };
}

function requestAssistantStreamStop() {
    if (!activeAssistantStream) {
        return;
    }
    if (activeAssistantStream.stopping) {
        return;
    }

    activeAssistantStream.stopping = true;
    if (activeAssistantStream.controls && activeAssistantStream.controls.stop) {
        activeAssistantStream.controls.stop.prop('disabled', true).text('Stopping…');
    }

    if (!activeAssistantStream.streamId) {
        activeAssistantStream.pendingStop = true;
        return;
    }

    fetch('stream_control.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            stream_id: activeAssistantStream.streamId,
            action: 'stop'
        })
    }).catch(function (err) {
        console.warn('Failed to signal stop:', err);
    });
}

function finalizeAssistantStream(finalPayload) {
    if (!activeAssistantStream) {
        return;
    }

    $('.waiting-indicator').hide();

    var streamContext = activeAssistantStream;
    var promptDocs = null;
    if (finalPayload && Array.isArray(finalPayload.prompt_documents)) {
        promptDocs = finalPayload.prompt_documents;
    } else if (Array.isArray(streamContext.promptDocuments)) {
        promptDocs = streamContext.promptDocuments;
    }
    if (streamContext.userMessageElement && promptDocs) {
        renderMessageAttachments(streamContext.userMessageElement, promptDocs);
    }

    var finalReply = (finalPayload && typeof finalPayload.reply === 'string')
        ? finalPayload.reply
        : (streamContext.accumulatedText || '');

    streamContext.accumulatedText = finalReply;

    if (streamContext.controls && streamContext.controls.stop) {
        streamContext.controls.stop.remove();
        streamContext.controls.stop = null;
    }
    if (streamContext.cursor) {
        streamContext.cursor.remove();
        streamContext.cursor = null;
    }
    if (streamContext.cursorFadeTimeout) {
        clearTimeout(streamContext.cursorFadeTimeout);
        streamContext.cursorFadeTimeout = null;
    }
    streamContext.stopVisible = false;

    if (streamContext.messageElement) {
        streamContext.messageElement.removeClass('streaming');
    }
    if (streamContext.contentElement) {
        streamContext.contentElement.remove();
    }

    var controlsWrapper = streamContext.controlsWrapper || null;
    if (controlsWrapper) {
        controlsWrapper.detach();
    }

    var formattedElement = $('<div class="assistant-content-final"></div>');
    if (finalReply && finalReply.trim() !== '') {
        var formattedHTML = formatCodeBlocks(finalReply);
        formattedElement.append(formattedHTML);
        streamContext.messageElement.append(formattedElement);
        if (controlsWrapper) {
            streamContext.messageElement.append(controlsWrapper);
        }
        var plainResponse = $('<div>').html(formattedHTML).text();
        addCopyButton(streamContext.messageElement, finalReply);
        addSpeakButton(streamContext.messageElement, plainResponse);
    } else {
        formattedElement.text('(no reply)');
        streamContext.messageElement.append(formattedElement);
        if (controlsWrapper) {
            streamContext.messageElement.append(controlsWrapper);
        }
    }
    streamContext.controlsWrapper = controlsWrapper;
    var pendingRedirect = finalPayload && finalPayload.new_chat_id;

    if (typeof fetchAndUpdateChatTitles === 'function') {
        var currentSearchTerm = (typeof search_term !== 'undefined') ? search_term : '';
        fetchAndUpdateChatTitles(currentSearchTerm, 0);
        if (!pendingRedirect) {
            setTimeout(function () {
                if (typeof fetchAndUpdateChatTitles === 'function') {
                    fetchAndUpdateChatTitles(currentSearchTerm, 0);
                }
            }, 1500);
        }
    }

    if (typeof hljs !== 'undefined' && hljs.highlightAll) {
        hljs.highlightAll();
    }
    if (window.MathJax) {
        MathJax.typesetPromise([streamContext.messageElement[0]])
            .then(debounceScroll)
            .catch(function (err) {
                console.error(err);
                debounceScroll();
            });
    } else {
        debounceScroll();
    }

    activeAssistantStream = null;

    if (pendingRedirect) {
        window.location.href = '/' + application_path + '/' + pendingRedirect;
    }
}

function failAssistantStream(message) {
    if (!activeAssistantStream) {
        return;
    }
    $('.waiting-indicator').hide();

    if (activeAssistantStream.messageElement) {
        activeAssistantStream.messageElement.removeClass('streaming');
    }
    if (activeAssistantStream.contentElement) {
        activeAssistantStream.contentElement
            .empty()
            .append(
                $('<div class="assistant-stream__error"></div>').text(message)
            );
    }

    if (activeAssistantStream.controls && activeAssistantStream.controls.stop) {
        activeAssistantStream.controls.stop.remove();
        activeAssistantStream.controls.stop = null;
    }
    if (activeAssistantStream.cursor) {
        activeAssistantStream.cursor.remove();
        activeAssistantStream.cursor = null;
    }
    if (activeAssistantStream.cursorFadeTimeout) {
        clearTimeout(activeAssistantStream.cursorFadeTimeout);
        activeAssistantStream.cursorFadeTimeout = null;
    }
    activeAssistantStream.stopVisible = false;
    if (activeAssistantStream.controlsWrapper) {
        activeAssistantStream.controlsWrapper = null;
    }

    activeAssistantStream = null;
}

function startAssistantStream(request) {
    if (activeAssistantStream) {
        console.warn('A reply is already streaming. Please wait.');
        return;
    }

    var payload = {
        message: request.encodedMessage,
        chat_id: chatId,
        deployment: typeof deployment !== 'undefined' ? deployment : '',
        exchange_type: request.exchangeType,
        custom_config: request.customConfig
    };

    var streamUi = createStreamingAssistantMessage(deployment, request);

    var controller = new AbortController();
    activeAssistantStream = {
        controller: controller,
        requestPayload: request,
        messageElement: streamUi.messageElement,
        contentElement: streamUi.contentElement,
        cursor: streamUi.cursor,
        controlsWrapper: streamUi.controlsWrapper,
        controls: streamUi.controls,
        accumulatedText: '',
        streamId: null,
        stopping: false,
        pendingStop: false,
        stopVisible: false,
        started: false,
        cursorFadeTimeout: null,
        userMessageElement: request.userMessageElement || null,
        promptDocuments: Array.isArray(request.promptDocuments) ? request.promptDocuments.slice() : null
    };

    streamUi.controls.stop.on('click', requestAssistantStreamStop);

    $('.waiting-indicator').show();

    fetch('sse.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify(payload),
        signal: controller.signal
    }).then(function (response) {
        if (!response.ok) {
            return response.text().catch(function () {
                return '';
            }).then(function (bodyText) {
                var suffix = bodyText ? (' - ' + bodyText) : '';
                throw new Error('HTTP ' + response.status + suffix);
            });
        }
        if (!response.body) {
            throw new Error('Streaming not supported by this browser.');
        }
        return readAssistantStream(response.body.getReader());
    }).catch(function (err) {
        if (err.name === 'AbortError') {
            return;
        }
        console.error('stream error', err);
        failAssistantStream(err.message || 'Assistant failed to respond.');
    }).finally(function () {
        $('.waiting-indicator').hide();
    });
}

function readAssistantStream(reader) {
    var decoder = new TextDecoder();
    var buffer = '';

    function processBuffer(forceFlush) {
        buffer = buffer.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
        var delimiterIndex;
        while ((delimiterIndex = buffer.indexOf('\n\n')) !== -1) {
            var chunk = buffer.slice(0, delimiterIndex);
            buffer = buffer.slice(delimiterIndex + 2);
            if (chunk.trim() === '') {
                continue;
            }
            handleAssistantStreamEvent(parseSseEventChunk(chunk));
        }
        if (forceFlush && buffer.trim() !== '') {
            handleAssistantStreamEvent(parseSseEventChunk(buffer));
            buffer = '';
        }
    }

    function pump() {
        return reader.read().then(function (result) {
            if (result.done) {
                processBuffer(true);
                reader.releaseLock();
                if (activeAssistantStream) {
                    finalizeAssistantStream({
                        reply: activeAssistantStream.accumulatedText,
                        chat_id: chatId,
                        new_chat_id: null,
                        stopped: activeAssistantStream ? activeAssistantStream.stopping : false
                    });
                }
                return;
            }
            buffer += decoder.decode(result.value, { stream: true });
            processBuffer(false);
            return pump();
        });
    }

    return pump();
}

function handleAssistantStreamEvent(event) {
    if (!activeAssistantStream) {
        return;
    }

    if (event.type === 'stream_open') {
        var streamId = event.data && event.data.stream_id ? event.data.stream_id : null;
        if (streamId) {
            activeAssistantStream.streamId = streamId;
            if (activeAssistantStream.pendingStop) {
                activeAssistantStream.pendingStop = false;
                requestAssistantStreamStop();
            }
        }
        return;
    }

    if (event.type === 'token') {
        if (activeAssistantStream.controls && activeAssistantStream.controls.stop && !activeAssistantStream.stopVisible) {
            activeAssistantStream.controls.stop.show();
            activeAssistantStream.stopVisible = true;
        }
        if (!activeAssistantStream.started) {
            activeAssistantStream.started = true;
            $('.waiting-indicator').hide();
        }

        var delta = '';
        if (event.data) {
            if (typeof event.data.delta === 'string') {
                delta = event.data.delta;
            } else if (typeof event.data === 'string') {
                delta = event.data;
            }
        } else if (typeof event.raw === 'string') {
            delta = event.raw;
        }

        if (typeof event.data === 'object' && event.data && typeof event.data.accumulated === 'string') {
            activeAssistantStream.accumulatedText = event.data.accumulated;
        } else {
            activeAssistantStream.accumulatedText += delta;
        }

        if (activeAssistantStream.contentElement) {
            var preview = normalizeStreamingPreview(activeAssistantStream.accumulatedText);
            var renderedHtml = renderStreamingPreviewHtml(preview);
            activeAssistantStream.contentElement.html(renderedHtml || '');
            if (typeof hljs !== 'undefined' && typeof hljs.highlightElement === 'function') {
                activeAssistantStream.contentElement.find('pre code').each(function (_, block) {
                    hljs.highlightElement(block);
                });
            }
            if (activeAssistantStream.cursor) {
                activeAssistantStream.cursor.removeClass('assistant-stream__cursor--fading');
                if (activeAssistantStream.cursorFadeTimeout) {
                    clearTimeout(activeAssistantStream.cursorFadeTimeout);
                }
                activeAssistantStream.cursorFadeTimeout = setTimeout(function () {
                    if (activeAssistantStream && activeAssistantStream.cursor) {
                        activeAssistantStream.cursor.addClass('assistant-stream__cursor--fading');
                    }
                }, 1000);
                activeAssistantStream.contentElement.append(activeAssistantStream.cursor);
            }
        }
        debounceScroll();
        return;
    }

    if (event.type === 'reasoning') {
        return;
    }

    if (event.type === 'tool_call') {
        console.debug('Tool call delta', event.data);
        return;
    }

    if (event.type === 'heartbeat') {
        return;
    }

    if (event.type === 'error') {
        var message = (event.data && event.data.message) ? event.data.message : 'Assistant returned an error.';
        failAssistantStream(message);
        return;
    }

    if (event.type === 'final') {
        if (event.data && Array.isArray(event.data.prompt_documents)) {
            activeAssistantStream.promptDocuments = event.data.prompt_documents;
        }
        finalizeAssistantStream(event.data || {});
        return;
    }
}

window.startAssistantStream = startAssistantStream;
window.requestAssistantStreamStop = requestAssistantStreamStop;
