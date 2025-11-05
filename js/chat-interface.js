'use strict';

var userMessageInput;
var PROMPT_HISTORY_LIMIT = 50;
var PROMPT_HISTORY_MENU_LIMIT = 20;
var promptHistoryStore = window.promptHistoryStore || {};
window.promptHistoryStore = promptHistoryStore;
var promptHistoryMenuState = {
    container: null,
    searchInput: null,
    listEl: null,
    emptyEl: null,
    open: false,
    filteredIndices: [],
    activeIndex: -1,
    lastChatId: null,
    documentHandlerBound: false
};
var isApplyingPromptHistory = false;

$(document).ready(initChatInterface);

function collectCurrentPromptDocuments() {
    if (typeof chatId === 'undefined' || !chatId) {
        return [];
    }
    window.chatDocumentsByChatId = window.chatDocumentsByChatId || {};
    var map = window.chatDocumentsByChatId[chatId] || {};

    var docItems = $('#doclist-' + chatId + ' .document-item');
    var snapshot = [];

    if (docItems && docItems.length) {
        docItems.each(function () {
            var $item = $(this);
            var docIdAttr = $item.attr('data-document-id');
            var docId = parseInt(docIdAttr || '0', 10);
            if (!Number.isFinite(docId) || docId <= 0) {
                return;
            }

            var entry = map[docId];
            if (!entry && typeof window.extractDocumentMetadataFromElement === 'function') {
                entry = window.extractDocumentMetadataFromElement($item);
                if (entry) {
                    map[docId] = entry;
                }
            }

            if (!entry) {
                return;
            }

            var clone = Object.assign({}, entry);
            clone.was_enabled = entry.enabled === false ? false : true;
            clone.enabled = clone.was_enabled;
            snapshot.push(clone);
        });

        window.chatDocumentsByChatId[chatId] = map;
    } else if (map && Object.keys(map).length) {
        Object.keys(map).sort(function (a, b) {
            return parseInt(a, 10) - parseInt(b, 10);
        }).forEach(function (key) {
            var entry = map[key];
            if (!entry) {
                return;
            }
            var clone = Object.assign({}, entry);
            clone.was_enabled = entry.enabled === false ? false : true;
            clone.enabled = clone.was_enabled;
            snapshot.push(clone);
        });
    }

    return snapshot;
}

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

    initializePromptHistoryUI();

    if (typeof adjustChatTitlesHeight === 'function') {
        adjustChatTitlesHeight();
    }

    if (typeof fetchAndUpdateChatTitles === 'function') {
        fetchAndUpdateChatTitles(typeof search_term !== 'undefined' ? search_term : '', 0);
    }

    userMessageInput.focus();
    loadMessages();

    userMessageInput.on('input', function () {
        if (isApplyingPromptHistory) {
            return;
        }
        resetPromptHistoryCycle(chatId);
    });

    userMessageInput.on('keydown', handleInputKeydown);

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

    storePromptHistoryEntry(chatId, sanitizedMessageContent);

    var messageContent = base64EncodeUnicode(sanitizedMessageContent);
    var exchangeType = $('#exchange_type').val();
    var customConfigVal = $('#custom_config').val();
    var promptDocsSnapshot = collectCurrentPromptDocuments();

    var userPromptElement = showUserPrompt(messageContent, exchangeType);
    if (promptDocsSnapshot.length && userPromptElement && userPromptElement.length) {
        renderMessageAttachments(userPromptElement, promptDocsSnapshot);
    }

    var requestPayload = {
        encodedMessage: messageContent,
        rawMessage: sanitizedMessageContent,
        exchangeType: exchangeType,
        customConfig: customConfigVal,
        promptDocuments: promptDocsSnapshot,
        userMessageElement: userPromptElement
    };

    startAssistantStream(requestPayload);
}

function getPromptHistoryState(chatId) {
    if (!chatId) {
        return null;
    }
    if (!promptHistoryStore[chatId]) {
        promptHistoryStore[chatId] = {
            items: [],
            pointer: -1,
            draftBeforeCycle: '',
            cycling: false
        };
    }
    return promptHistoryStore[chatId];
}

function resetPromptHistoryCycle(chatId) {
    var state = getPromptHistoryState(chatId);
    if (!state) {
        return;
    }
    state.pointer = -1;
    state.draftBeforeCycle = '';
    state.cycling = false;
}

function storePromptHistoryEntry(currentChatId, prompt) {
    if (!currentChatId) {
        return;
    }
    var normalized = normalizePromptText(prompt);
    if (!normalized) {
        return;
    }
    var state = getPromptHistoryState(currentChatId);
    if (!state) {
        return;
    }
    var items = state.items;
    if (items.length && items[items.length - 1] === normalized) {
        resetPromptHistoryCycle(currentChatId);
        return;
    }
    items.push(normalized);
    if (items.length > PROMPT_HISTORY_LIMIT) {
        items.shift();
    }
    resetPromptHistoryCycle(currentChatId);
}

function normalizePromptText(input) {
    if (!input) {
        return '';
    }
    var trimmed = String(input).replace(/[\r\n]+/g, '\n').trim();
    return trimmed;
}

function seedPromptHistoryFromLoadedPrompts(currentChatId, prompts) {
    if (!currentChatId) {
        return;
    }
    var state = getPromptHistoryState(currentChatId);
    if (!state) {
        return;
    }

    var normalizedItems = [];
    (prompts || []).forEach(function (promptVal) {
        var normalized = normalizePromptText(promptVal);
        if (!normalized) {
            return;
        }
        if (normalizedItems.length && normalizedItems[normalizedItems.length - 1] === normalized) {
            return;
        }
        normalizedItems.push(normalized);
    });

    if (normalizedItems.length > PROMPT_HISTORY_LIMIT) {
        normalizedItems = normalizedItems.slice(normalizedItems.length - PROMPT_HISTORY_LIMIT);
    }

    state.items = normalizedItems;
    state.pointer = -1;
    state.draftBeforeCycle = '';
    state.cycling = false;

    if (promptHistoryMenuState.open && promptHistoryMenuState.lastChatId === currentChatId) {
        updatePromptHistoryMenuRendering(currentChatId, { preserveActive: true });
    }
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
            tempForm.style.display = 'inline-block';
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

    return userMessageElement;
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
    var promptsForHistory = [];

    Object.values(chatMessages || {}).forEach(function (message) {
        var exchangeType = message.exchange_type || 'chat';
        var icon = exchangeType === 'workflow' ? 'gear_icon.png' : 'user.png';
        if (message && message.prompt) {
            promptsForHistory.push(message.prompt);
        }
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

    seedPromptHistoryFromLoadedPrompts(chatId, promptsForHistory);
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

function handleInputKeydown(event) {
    if (!event) {
        return;
    }

    var key = event.key || (event.originalEvent && event.originalEvent.key) || '';
    var keyCode = typeof event.which === 'number' && event.which !== 0 ? event.which : event.keyCode;
    if (!key) {
        if (keyCode === 38 || keyCode === 63232) {
            key = 'ArrowUp';
        } else if (keyCode === 40 || keyCode === 63233) {
            key = 'ArrowDown';
        } else if (keyCode === 13) {
            key = 'Enter';
        } else if (keyCode === 27) {
            key = 'Escape';
        }
    } else {
        if (key === 'ArrowUp' || key === 'Up' || key === 'PageUp' || keyCode === 63232) {
            key = 'ArrowUp';
        } else if (key === 'ArrowDown' || key === 'Down' || key === 'PageDown' || keyCode === 63233) {
            key = 'ArrowDown';
        }
    }

    if (key === 'Enter' && !event.shiftKey && !event.altKey && !event.ctrlKey && !event.metaKey) {
        event.preventDefault();
        $('#messageForm').submit();
        return;
    }

    if (event.altKey && !event.ctrlKey && !event.metaKey) {
        if (key === 'ArrowUp') {
            event.preventDefault();
            handlePromptHistoryAltUp();
            return;
        }
        if (key === 'ArrowDown') {
            event.preventDefault();
            handlePromptHistoryAltDown();
            return;
        }
    }

    if (key === 'Escape' && promptHistoryMenuState.open) {
        event.preventDefault();
        closePromptHistoryMenu();
        userMessageInput.focus();
    }
}

function initializePromptHistoryUI() {
    if (!userMessageInput || !userMessageInput.length) {
        return;
    }
    var inputContainer = userMessageInput.closest('.input-container');
    if (!inputContainer.length) {
        return;
    }

    if (promptHistoryMenuState.container && promptHistoryMenuState.container.length) {
        promptHistoryMenuState.container.remove();
    }

    var menu = $('<div class="prompt-history-menu" aria-hidden="true"></div>').hide();
    var header = $('<div class="prompt-history-header"></div>');
    var search = $('<input type="text" class="prompt-history-search" placeholder="Search prompts…" aria-label="Search prompt history">');
    var list = $('<ul class="prompt-history-list" role="listbox"></ul>');
    var empty = $('<div class="prompt-history-empty">No matching prompts.</div>').hide();

    header.append(search);
    menu.append(header, list, empty);
    inputContainer.append(menu);

    search.on('input', function () {
        updatePromptHistoryMenuRendering(chatId, { preserveActive: true });
    });
    search.on('keydown', handlePromptHistorySearchKeydown);

    list.on('click', '.prompt-history-item', function (event) {
        event.preventDefault();
        var indexAttr = $(this).attr('data-history-index');
        var originalIndex = parseInt(indexAttr, 10);
        if (!Number.isFinite(originalIndex)) {
            return;
        }
        applyPromptHistoryByIndex(originalIndex, { closeMenu: true, focusComposer: true });
    });

    promptHistoryMenuState.container = menu;
    promptHistoryMenuState.searchInput = search;
    promptHistoryMenuState.listEl = list;
    promptHistoryMenuState.emptyEl = empty;

    if (!promptHistoryMenuState.documentHandlerBound) {
        $(document).on('mousedown', handlePromptHistoryDocumentMouseDown);
        promptHistoryMenuState.documentHandlerBound = true;
    }
}

function handlePromptHistoryAltUp() {
    var state = getPromptHistoryState(chatId);
    if (!state || !state.items.length) {
        return;
    }

    if (!promptHistoryMenuState.open) {
        stepPromptHistory(-1, { openMenu: true });
    } else {
        movePromptHistorySelection(-1);
    }
}

function handlePromptHistoryAltDown() {
    var state = getPromptHistoryState(chatId);
    if (!state || !state.items.length) {
        return;
    }

    if (promptHistoryMenuState.open) {
        movePromptHistorySelection(1);
    } else {
        stepPromptHistory(1, { openMenu: false });
    }
}

function stepPromptHistory(direction, options) {
    var state = getPromptHistoryState(chatId);
    if (!state || !state.items.length) {
        return;
    }

    if (!state.cycling) {
        state.draftBeforeCycle = userMessageInput.val();
        state.cycling = true;
        state.pointer = state.items.length;
    }

    var nextPointer = state.pointer + direction;
    if (nextPointer < 0) {
        nextPointer = 0;
    }

    if (nextPointer >= state.items.length) {
        var draft = state.draftBeforeCycle || '';
        resetPromptHistoryCycle(chatId);
        applyPromptHistoryValue(draft);
        if (!options || options.keepMenu !== true) {
            closePromptHistoryMenu();
        }
        return;
    }

    state.pointer = nextPointer;
    var value = state.items[state.pointer];
    applyPromptHistoryValue(value);

    if (options && options.openMenu) {
        openPromptHistoryMenu({ activeIndex: state.pointer });
    } else if (promptHistoryMenuState.open) {
        highlightPromptHistoryIndex(state.pointer);
    }
}

function applyPromptHistoryValue(value) {
    isApplyingPromptHistory = true;
    userMessageInput.val(value);
    userMessageInput.trigger('input');
    isApplyingPromptHistory = false;

    try {
        var inputEl = userMessageInput.get(0);
        if (inputEl && inputEl.setSelectionRange) {
            var length = value ? value.length : 0;
            inputEl.setSelectionRange(length, length);
        }
    } catch (err) {
        console.warn('Unable to set selection range for prompt history', err);
    }
}

function openPromptHistoryMenu(options) {
    if (!promptHistoryMenuState.container || !promptHistoryMenuState.searchInput) {
        return;
    }

    promptHistoryMenuState.lastChatId = chatId;
    promptHistoryMenuState.open = true;
    promptHistoryMenuState.container.attr('aria-hidden', 'false').show();

    if (promptHistoryMenuState.searchInput) {
        var query = options && options.searchQuery ? options.searchQuery : '';
        promptHistoryMenuState.searchInput.val(query);
    }

    var forceIndex = options && options.activeIndex !== undefined ? options.activeIndex : getPromptHistoryState(chatId).pointer;
    updatePromptHistoryMenuRendering(chatId, { forceIndex: forceIndex });

    setTimeout(function () {
        if (promptHistoryMenuState.searchInput && promptHistoryMenuState.searchInput.length) {
            promptHistoryMenuState.searchInput.focus();
            promptHistoryMenuState.searchInput[0].setSelectionRange(0, promptHistoryMenuState.searchInput.val().length);
        }
    }, 0);
}

function closePromptHistoryMenu() {
    if (!promptHistoryMenuState.open) {
        return;
    }
    promptHistoryMenuState.open = false;
    if (promptHistoryMenuState.container) {
        promptHistoryMenuState.container.hide().attr('aria-hidden', 'true');
    }
    promptHistoryMenuState.filteredIndices = [];
    promptHistoryMenuState.activeIndex = -1;
    if (promptHistoryMenuState.listEl) {
        promptHistoryMenuState.listEl.hide();
    }
    if (promptHistoryMenuState.emptyEl) {
        promptHistoryMenuState.emptyEl.hide();
    }
    if (promptHistoryMenuState.searchInput) {
        promptHistoryMenuState.searchInput.val('');
    }
}

function updatePromptHistoryMenuRendering(currentChatId, options) {
    if (!promptHistoryMenuState.listEl) {
        return;
    }
    var state = getPromptHistoryState(currentChatId);
    if (!state) {
        promptHistoryMenuState.listEl.empty();
        promptHistoryMenuState.emptyEl && promptHistoryMenuState.emptyEl.show();
        return;
    }

    var previousOriginal = -1;
    if (promptHistoryMenuState.filteredIndices && promptHistoryMenuState.filteredIndices.length && promptHistoryMenuState.activeIndex !== -1) {
        previousOriginal = promptHistoryMenuState.filteredIndices[promptHistoryMenuState.activeIndex];
    }

    var query = '';
    if (promptHistoryMenuState.searchInput) {
        query = (promptHistoryMenuState.searchInput.val() || '').toLowerCase();
    }

    var indices = [];
    for (var i = state.items.length - 1; i >= 0; i--) {
        var prompt = state.items[i];
        if (query && prompt.toLowerCase().indexOf(query) === -1) {
            continue;
        }
        indices.push(i);
        if (indices.length >= PROMPT_HISTORY_MENU_LIMIT) {
            break;
        }
    }

    promptHistoryMenuState.filteredIndices = indices;
    promptHistoryMenuState.listEl.empty();

    if (!indices.length) {
        promptHistoryMenuState.listEl.hide();
        promptHistoryMenuState.emptyEl && promptHistoryMenuState.emptyEl.show();
        promptHistoryMenuState.activeIndex = -1;
        return;
    }

    promptHistoryMenuState.emptyEl && promptHistoryMenuState.emptyEl.hide();
    promptHistoryMenuState.listEl.show();

    indices.forEach(function (originalIndex) {
        var promptValue = state.items[originalIndex] || '';
        var summary = summarizePromptForHistory(promptValue);
        var item = $('<li class="prompt-history-item" role="option"></li>');
        item.attr('data-history-index', originalIndex);
        item.text(summary);
        promptHistoryMenuState.listEl.append(item);
    });

    var activeOriginal = -1;
    if (options && options.forceIndex !== undefined && options.forceIndex !== null) {
        activeOriginal = options.forceIndex;
    } else if (options && options.preserveActive && previousOriginal !== -1) {
        activeOriginal = previousOriginal;
    } else if (state.pointer !== -1) {
        activeOriginal = state.pointer;
    }

    var containsActive = false;
    if (activeOriginal !== -1) {
        for (var j = 0; j < promptHistoryMenuState.filteredIndices.length; j++) {
            if (promptHistoryMenuState.filteredIndices[j] === activeOriginal) {
                containsActive = true;
                break;
            }
        }
    }
    if (activeOriginal !== -1 && !containsActive) {
        activeOriginal = promptHistoryMenuState.filteredIndices[0];
    }

    highlightPromptHistoryIndex(activeOriginal);
}

function highlightPromptHistoryIndex(originalIndex) {
    if (!promptHistoryMenuState.listEl) {
        return;
    }
    promptHistoryMenuState.listEl.children().removeClass('is-active').attr('aria-selected', 'false');

    if (!Number.isFinite(originalIndex) || originalIndex < 0) {
        promptHistoryMenuState.activeIndex = -1;
        return;
    }

    var filtered = promptHistoryMenuState.filteredIndices || [];
    var position = -1;
    for (var i = 0; i < filtered.length; i++) {
        if (filtered[i] === originalIndex) {
            position = i;
            break;
        }
    }

    if (position === -1) {
        promptHistoryMenuState.activeIndex = -1;
        return;
    }

    var item = promptHistoryMenuState.listEl.children().eq(position);
    if (item && item.length) {
        item.addClass('is-active').attr('aria-selected', 'true');
        if (item[0] && item[0].scrollIntoView) {
            item[0].scrollIntoView({ block: 'nearest' });
        }
        promptHistoryMenuState.activeIndex = position;
    } else {
        promptHistoryMenuState.activeIndex = -1;
    }
}

function movePromptHistorySelection(delta) {
    if (!promptHistoryMenuState.open) {
        return;
    }
    var filtered = promptHistoryMenuState.filteredIndices || [];
    if (!filtered.length) {
        return;
    }

    var next = promptHistoryMenuState.activeIndex;
    if (next === -1) {
        next = delta < 0 ? 0 : 0;
    } else {
        next += delta;
    }

    if (next < 0) {
        next = 0;
    }
    if (next >= filtered.length) {
        next = filtered.length - 1;
    }

    promptHistoryMenuState.activeIndex = next;
    var originalIndex = filtered[next];
    applyPromptHistoryByIndex(originalIndex, { keepMenuOpen: true, focusComposer: false });
}

function handlePromptHistorySearchKeydown(event) {
    var key = event.key || (event.keyCode === 38 ? 'ArrowUp' : event.keyCode === 40 ? 'ArrowDown' : event.keyCode === 13 ? 'Enter' : event.keyCode === 27 ? 'Escape' : null);

    if (key === 'ArrowDown') {
        event.preventDefault();
        movePromptHistorySelection(1);
    } else if (key === 'ArrowUp') {
        event.preventDefault();
        movePromptHistorySelection(-1);
    } else if (key === 'Enter') {
        event.preventDefault();
        commitPromptHistorySelection();
    } else if (key === 'Escape') {
        event.preventDefault();
        closePromptHistoryMenu();
        userMessageInput.focus();
    }
}

function commitPromptHistorySelection() {
    var filtered = promptHistoryMenuState.filteredIndices || [];
    if (!filtered.length) {
        return;
    }
    var originalIndex;
    if (promptHistoryMenuState.activeIndex !== -1 && filtered[promptHistoryMenuState.activeIndex] !== undefined) {
        originalIndex = filtered[promptHistoryMenuState.activeIndex];
    } else {
        originalIndex = filtered[0];
    }
    applyPromptHistoryByIndex(originalIndex, { closeMenu: true, focusComposer: true });
}

function summarizePromptForHistory(prompt) {
    if (!prompt) {
        return '(empty prompt)';
    }
    var normalized = prompt.replace(/\s+/g, ' ').trim();
    if (normalized.length > 120) {
        return normalized.slice(0, 117) + '…';
    }
    return normalized;
}

function applyPromptHistoryByIndex(originalIndex, options) {
    var state = getPromptHistoryState(chatId);
    if (!state || !state.items.length) {
        return;
    }
    if (!Number.isFinite(originalIndex) || originalIndex < 0 || originalIndex >= state.items.length) {
        return;
    }

    if (!state.cycling) {
        state.draftBeforeCycle = userMessageInput.val();
        state.cycling = true;
    }

    state.pointer = originalIndex;
    var value = state.items[originalIndex];
    applyPromptHistoryValue(value);

    if (options && options.keepMenuOpen) {
        highlightPromptHistoryIndex(originalIndex);
    }

    if (options && options.closeMenu) {
        closePromptHistoryMenu();
    }

    if (options && options.focusComposer) {
        setTimeout(function () {
            userMessageInput.focus();
            try {
                var elem = userMessageInput.get(0);
                if (elem && elem.setSelectionRange) {
                    var val = userMessageInput.val();
                    elem.setSelectionRange(val.length, val.length);
                }
            } catch (err) {
                console.warn('Unable to restore focus after prompt history select', err);
            }
        }, 0);
    } else if (promptHistoryMenuState.open) {
        highlightPromptHistoryIndex(originalIndex);
    }
}

function handlePromptHistoryDocumentMouseDown(event) {
    if (!promptHistoryMenuState.open) {
        return;
    }
    var target = event.target;
    if (!target) {
        return;
    }
    var $target = $(target);
    if ($target.closest('.prompt-history-menu').length) {
        return;
    }
    closePromptHistoryMenu();
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
