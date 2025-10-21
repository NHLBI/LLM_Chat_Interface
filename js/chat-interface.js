'use strict';

var userMessageInput;

$(document).ready(initChatInterface);

function initChatInterface() {
    searchingIndicator = document.getElementById('searching-indicator');
    chatTitlesContainer = document.querySelector('.chat-titles-container');

    if (typeof updatePlaceholder === 'function') {
        updatePlaceholder();
    }

    initializeDocumentProcessingElements();

    chatContainer = $('.chat-container');
    userMessageInput = $('#userMessage');
    originalMessagePlaceholder = userMessageInput.attr('placeholder') || '';

    if (typeof adjustChatTitlesHeight === 'function') {
        adjustChatTitlesHeight();
    }

    if (typeof fetchAndUpdateChatTitles === 'function') {
        fetchAndUpdateChatTitles(typeof search_term !== 'undefined' ? search_term : '', 0);
    }

    userMessageInput.focus();
    loadMessages();

    userMessageInput.on('keydown', function (event) {
        if (event.keyCode === 13 && !event.shiftKey) {
            event.preventDefault();
            $('#messageForm').submit();
        }
    });

    $(document).on('click', '.edit-confirm-icon', function () {
        var sourceId = $(this).parent().prev().attr('id');
        if (!sourceId) {
            return;
        }
        var parts = sourceId.split('-');
        if (parts.length >= 3) {
            submitEdit(parts[2]);
        }
    });

    $('#messageForm').on('submit', handleMessageSubmit);
}

function handleMessageSubmit(event) {
    event.preventDefault();

    if (window.isDocumentProcessing) {
        notifyDocumentProcessingInProgress();
        return;
    }

    if (activeAssistantStream) {
        console.warn('A reply is already streaming. Please wait for it to finish.');
        return;
    }

    var rawMessageContent = userMessageInput.val().trim();
    var sanitizedMessageContent = replaceNonAsciiCharacters(rawMessageContent);
    if (!sanitizedMessageContent) {
        return;
    }

    var messageContent = base64EncodeUnicode(sanitizedMessageContent);
    var exchangeType = $('#exchange_type').val();
    var customConfigVal = $('#custom_config').val();

    showUserPrompt(messageContent, exchangeType);

    var requestPayload = {
        encodedMessage: messageContent,
        rawMessage: sanitizedMessageContent,
        exchangeType: exchangeType,
        customConfig: customConfigVal
    };

    startAssistantStream(requestPayload);
}

function showUserPrompt(encodedMessage, exchangeType) {
    var decoded = base64DecodeUnicode(encodedMessage);
    var sanitizedPrompt = formatCodeBlocks(decoded);

    var icon = exchangeType === 'workflow' ? 'gear_icon.png' : 'user.png';
    if (exchangeType === 'workflow') {
        document.getElementById('messageForm').style.display = 'none';
        document.getElementById('modelSelectButton').style.display = 'none';
        document.getElementById('temperature_select').style.display = 'none';

        var workflowContainer = document.querySelector('.maincol-top');
        if (workflowContainer) {
            workflowContainer.style.height = 'calc(100vh - 140px)';
        }
    } else {
        document.getElementById('messageForm').style.display = 'block';
        document.getElementById('modelSelectButton').style.display = 'inline-block';
        var tempForm = document.getElementById('temperature_select');
        if (tempForm) {
            tempForm.style.display = 'block';
        }
        var mainColTop = document.querySelector('.maincol-top');
        if (mainColTop) {
            mainColTop.style.height = 'calc(100vh - 240px)';
        }
    }

    var userMessageElement = $('<div class="message user-message"></div>').html(sanitizedPrompt);
    userMessageElement.prepend('<img src="images/' + icon + '" class="user-icon" alt="User icon">');
    chatContainer.append(userMessageElement);

    userMessageElement.find('pre code').each(function (_, block) {
        hljs.highlightElement(block);
    });

    if (deployment !== 'azure-dall-e-3' && typeof fetchUserImages === 'function') {
        fetchUserImages(chatId, userMessageElement);
    }

    if (window.MathJax) {
        MathJax.typesetPromise([userMessageElement[0]])
            .then(debounceScroll)
            .catch(function (err) {
                console.error('MathJax typeset failed:', err);
                debounceScroll();
            });
    } else {
        debounceScroll();
    }

    userMessageInput.val('');
    if (typeof safeRemoveChatDraft === 'function') {
        safeRemoveChatDraft('chatDraft_' + chatId);
    } else {
        try {
            localStorage.removeItem('chatDraft_' + chatId);
        } catch (err) {
            console.warn('Unable to clear chat draft cache', err);
        }
    }
}

function loadMessages() {
    $.ajax({
        url: 'get_messages.php',
        data: { chat_id: chatId, user: user },
        dataType: 'json',
        success: function (chatMessages) {
            if (typeof syncProcessingStateFromServer === 'function') {
                syncProcessingStateFromServer(chatMessages);
            }
            displayMessages(chatMessages);
            debounceScroll();
        }
    });
}

function displayMessages(chatMessages) {
    stopActiveAudio();
    if (typeof resetSpeakButton === 'function' && activeSpeakButton) {
        resetSpeakButton(activeSpeakButton);
        activeSpeakButton = null;
    }

    chatContainer.empty();

    Object.values(chatMessages || {}).forEach(function (message) {
        var exchangeType = message.exchange_type || 'chat';
        var icon = exchangeType === 'workflow' ? 'gear_icon.png' : 'user.png';
        var sanitizedPrompt = formatCodeBlocks(message.prompt || '');
        var userMessageElement = $('<div class="message user-message"></div>').html(sanitizedPrompt);
        userMessageElement.prepend('<img src="images/' + icon + '" class="user-icon" alt="User icon">');

        chatContainer.append(userMessageElement);

        var assistantMessageElement = null;
        if (message.deployment && deployments[message.deployment]) {
            assistantMessageElement = $('<div class="message assistant-message"></div>');
            var deploymentMeta = deployments[message.deployment];
            var avatarSrc = 'images/' + deploymentMeta.image;
            var avatarAlt = deploymentMeta.image_alt;
            assistantMessageElement.prepend('<img src="' + avatarSrc + '" alt="' + avatarAlt + '" class="openai-icon">');
            chatContainer.append(assistantMessageElement);

            if (message.reply) {
                var formattedReply = formatCodeBlocks(message.reply);
                assistantMessageElement.append(formattedReply);
                addCopyButton(assistantMessageElement, message.reply);
                var plainReply = $('<div>').html(formattedReply).text();
                addSpeakButton(assistantMessageElement, plainReply);
            }
        }

        if (message.image_gen_name) {
            var genImg = $('<img>')
                .attr('class', 'image-message')
                .attr('src', '../image_gen/small/' + message.image_gen_name)
                .attr('alt', 'Generated Image')
                .on('load', debounceScroll);

            if (assistantMessageElement) {
                assistantMessageElement.append(genImg);
                addDownloadButton(assistantMessageElement, '../image_gen/fullsize/' + message.image_gen_name);
            } else {
                var imageContainer = $('<div class="message assistant-message"></div>');
                imageContainer.append(genImg);
                addDownloadButton(imageContainer, '../image_gen/fullsize/' + message.image_gen_name);
                chatContainer.append(imageContainer);
            }
        }

        if (message.document) {
            renderMessageAttachments(userMessageElement, message.document);
        }
    });

    if (typeof hljs !== 'undefined' && hljs.highlightAll) {
        hljs.highlightAll();
    }
    if (window.MathJax) {
        MathJax.typesetPromise([chatContainer[0]])
            .then(debounceScroll)
            .catch(function (err) {
                console.error('MathJax typeset failed:', err);
                debounceScroll();
            });
    } else {
        debounceScroll();
    }
}

function addDownloadButton(messageElement, fullImagePath) {
    var downloadButton = $(
        '<button class="copy-chat-button" title="Download Full Image" aria-label="Download the full image">' +
        '<span style="font-size:12px;">Download Full Image</span>' +
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">' +
        '<path d="M12.75 20.379v-10.573h-1.5v10.573l-2.432-2.432-1.061 1.061 4.243 4.243 4.243-4.243-1.061-1.061-2.432 2.432z"></path>' +
        '<path d="M18.75 7.555c0-3.722-3.028-6.75-6.75-6.75s-6.75 3.028-6.75 6.75c-2.485 0-4.5 2.015-4.5 4.5s2.015 4.5 4.5 4.5h3.75v-1.5h-3.75c-1.657 0-3-1.343-3-3s1.343-3 3-3v0h1.5v-1.5c0-2.899 2.351-5.25 5.25-5.25s5.25 2.351 5.25 5.25v0 1.5h1.5c1.657 0 3 1.343 3 3s-1.343 3-3 3v0h-3.75v1.5h3.75c2.485 0 4.5-2.015 4.5-4.5s-2.015-4.5-4.5-4.5v0z"></path>' +
        '</svg>' +
        '</button>'
    );

    downloadButton.on('click', function () {
        var link = document.createElement('a');
        link.href = fullImagePath;
        link.download = 'GeneratedImage.png';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    });

    var controlsWrapper = ensureReplyControls(messageElement);
    controlsWrapper.append(downloadButton);
}

function recordClientEvent(eventType, data) {
    try {
        var payload = Object.assign({
            event: eventType,
            timestamp: new Date().toISOString(),
            chat_id: typeof chatId !== 'undefined' ? chatId : null
        }, data || {});

        var json = JSON.stringify(payload);
        if (navigator.sendBeacon) {
            var blob = new Blob([json], { type: 'application/json' });
            navigator.sendBeacon('log_client_event.php', blob);
        } else {
            fetch('log_client_event.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                keepalive: true,
                body: json
            }).catch(function (err) {
                console.error('recordClientEvent fetch error', err);
            });
        }
    } catch (err) {
        console.error('recordClientEvent failed', err);
    }
}
