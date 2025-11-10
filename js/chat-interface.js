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

function isProbableSmilesJS(token) {
    if (!token || typeof token !== 'string') {
        return false;
    }

    if (token.length === 0 || token.length > 1024) {
        return false;
    }

    if (/\s/u.test(token)) {
        return false;
    }

    if (!/[A-Za-z]/.test(token)) {
        return false;
    }

    if (!/^[A-Za-z0-9#%=\+\-\[\]\(\)@\/\\\.]+$/.test(token)) {
        return false;
    }

    if (token.length === 1) {
        return false;
    }

    if (/(?:Cl|Br|Si|Na|Li|Mg|Ca|K|Fe|Cu|Zn|Hg|Al|Sn|Pb|Ag|Au|Ni|Co|Mn|Ti|B|C|N|O|S|P|F|I|H)/.test(token)) {
        return true;
    }

    return /\d/.test(token);
}

function cleanSmilesCandidate(raw) {
    if (!raw) {
        return '';
    }
    return raw.replace(/^[("'“‘\[]+|[)"'”’\].,;:!?]+$/g, '');
}

function extractSmilesCommands(text) {
    var result = {
        tokens: [],
        cleanedText: typeof text === 'string' ? text : ''
    };
    if (!text || typeof text !== 'string') {
        return result;
    }

    var regex = /\/smiles\b/gi;
    var match;
    var removalRanges = [];

    while ((match = regex.exec(text)) !== null) {
        var commandStart = match.index;
        var cursor = regex.lastIndex;

        while (cursor < text.length && /\s/.test(text.charAt(cursor))) {
            cursor += 1;
        }

        var lastTokenEnd = cursor;
        var foundAnyToken = false;

        while (cursor < text.length) {
            var wordStart = cursor;
            while (cursor < text.length && !/\s/.test(text.charAt(cursor))) {
                cursor += 1;
            }
            var wordEnd = cursor;
            if (wordStart === wordEnd) {
                break;
            }
            var rawWord = text.slice(wordStart, wordEnd);
            var cleanedWord = cleanSmilesCandidate(rawWord);
            if (cleanedWord && isProbableSmilesJS(cleanedWord)) {
                result.tokens.push(cleanedWord);
                foundAnyToken = true;
                lastTokenEnd = wordEnd;
                while (cursor < text.length && /\s/.test(text.charAt(cursor))) {
                    cursor += 1;
                    lastTokenEnd = cursor;
                }
            } else {
                break;
            }
        }

        var removalEnd = foundAnyToken ? lastTokenEnd : regex.lastIndex;
        removalRanges.push([commandStart, removalEnd]);
        regex.lastIndex = cursor;
    }

    if (!removalRanges.length) {
        result.cleanedText = text.trim();
        return result;
    }

    var rebuilt = [];
    var lastIndex = 0;
    removalRanges.forEach(function (range) {
        var start = range[0];
        var end = range[1];
        if (start > lastIndex) {
            rebuilt.push(text.slice(lastIndex, start));
        }
        lastIndex = Math.max(lastIndex, end);
    });
    if (lastIndex < text.length) {
        rebuilt.push(text.slice(lastIndex));
    }
    var cleanedText = rebuilt.join('');
    cleanedText = cleanedText.replace(/[ \t]{2,}/g, ' ');
    cleanedText = cleanedText.replace(/ +\n/g, '\n').replace(/\n +/g, '\n');
    cleanedText = cleanedText.replace(/\s+$/g, '');
    result.cleanedText = cleanedText.trim();
    return result;
}

function resolveAppPath(path) {
    if (!path) {
        return path;
    }
    if (/^(https?:)?\/\//i.test(path)) {
        return path;
    }
    if (path.charAt(0) === '/') {
        return path;
    }
    var base = typeof application_path === 'string' ? application_path.replace(/^\/+|\/+$/g, '') : '';
    if (base === '') {
        return '/' + path.replace(/^\/+/, '');
    }
    return '/' + base + '/' + path.replace(/^\/+/, '');
}

async function postJSON(path, payload) {
    var url = resolveAppPath(path);
    var response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify(payload || {})
    });
    if (!response.ok) {
        var errorText = 'HTTP ' + response.status;
        try {
            var errJson = await response.json();
            if (errJson && errJson.message) {
                errorText = errJson.message;
            }
        } catch (err) {
            // swallow JSON parse issues
        }
        throw new Error(errorText);
    }
    var json = null;
    try {
        json = await response.json();
    } catch (err) {
        throw new Error('Invalid JSON response');
    }
    return json;
}

function smilesClientDebug(stage, extra) {
    try {
        var payload = {
            event: 'smiles_debug',
            stage: stage,
            chat_id: typeof chatId !== 'undefined' ? chatId : null,
            details: extra || {}
        };
        var body = JSON.stringify(payload);
        var url = resolveAppPath('log_client_event.php');
        if (navigator && typeof navigator.sendBeacon === 'function') {
            var blob = new Blob([body], { type: 'application/json' });
            if (!navigator.sendBeacon(url, blob)) {
                fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: body
                }).catch(function () { /* swallow */ });
            }
        } else {
            fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body
            }).catch(function () { /* swallow */ });
        }
    } catch (err) {
        console.warn('smilesClientDebug failed', err);
    }
}

async function fetchPngAsDataUrl(pngUrl) {
    if (!pngUrl) {
        throw new Error('PNG URL missing');
    }
    var url = resolveAppPath(pngUrl);
    var response = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin'
    });
    if (!response.ok) {
        throw new Error('Failed to fetch molecule image');
    }
    var blob = await response.blob();
    return await new Promise(function (resolve, reject) {
        var reader = new FileReader();
        reader.onload = function () {
            resolve(reader.result);
        };
        reader.onerror = function (event) {
            reject(event && event.target && event.target.error ? event.target.error : new Error('Failed to read image blob'));
        };
        reader.readAsDataURL(blob);
    });
}

function dataUrlToBlob(dataUrl) {
    try {
        var parts = dataUrl.split(',');
        if (parts.length < 2) {
            return null;
        }
        var mimeMatch = parts[0].match(/data:(.*?);base64/i);
        var mimeType = mimeMatch && mimeMatch[1] ? mimeMatch[1] : 'application/octet-stream';
        var binary = atob(parts[1]);
        var len = binary.length;
        var buffer = new Uint8Array(len);
        for (var i = 0; i < len; i++) {
            buffer[i] = binary.charCodeAt(i);
        }
        return new Blob([buffer], { type: mimeType });
    } catch (err) {
        console.error('dataUrlToBlob error', err);
        return null;
    }
}

function buildSmilesFileName(label) {
    var safe = (label || '').trim();
    if (safe === '') {
        safe = 'molecule';
    }
    safe = safe.replace(/[^\w\-.]+/g, '_').replace(/_+/g, '_');
    if (safe.length > 120) {
        safe = safe.slice(0, 120);
    }
    return 'molecule_' + safe + '.png';
}

async function fetchDocumentPreview(documentId) {
    if (!documentId) {
        return null;
    }
    var url = resolveAppPath('document_excerpt.php') + '?document_id=' + encodeURIComponent(documentId);
    try {
        var response = await fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        if (!response.ok) {
            return null;
        }
        var json = await response.json();
        if (json && json.ok && json.document) {
            return json.document;
        }
    } catch (err) {
        console.warn('fetchDocumentPreview failed', err);
    }
    return null;
}

async function uploadSmilesImage(dataUrl, smilesToken, canonicalLabel) {
    var blob = dataUrlToBlob(dataUrl);
    if (!blob) {
        throw new Error('Unable to prepare molecule image');
    }

    var effectiveCanonical = canonicalLabel || smilesToken;
    var displayName = 'Molecule: ' + effectiveCanonical;
    var fileName = buildSmilesFileName(effectiveCanonical);
    var file;
    try {
        file = new File([blob], fileName, { type: 'image/png' });
    } catch (err) {
        var fallbackBlob = blob.slice(0, blob.size, 'image/png');
        fallbackBlob.name = fileName;
        file = fallbackBlob;
    }

    var formData = new FormData();
    var chatIdentifier = (typeof chatId !== 'undefined' && chatId) ? chatId : '';
    formData.append('chat_id', chatIdentifier);
    formData.append('smiles_generated', '1');
    formData.append('smiles_label', effectiveCanonical);
    if (window.selectedWorkflow) {
        try {
            formData.append('selected_workflow', JSON.stringify(window.selectedWorkflow));
        } catch (err) {
            console.warn('Unable to serialize selectedWorkflow for upload', err);
        }
    }
    var appendName = (file && file.name) ? file.name : fileName;
    formData.append('uploadDocument[]', file, appendName);

    var response = await fetch(resolveAppPath('upload.php'), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    });

    if (!response.ok) {
        throw new Error('Upload failed with status ' + response.status);
    }

    var resultJson = {};
    try {
        resultJson = await response.json();
    } catch (err) {
        throw new Error('Upload response was not JSON');
    }

    if (resultJson && resultJson.new_chat && resultJson.chat_id) {
        window.location.href = '/' + application_path + '/' + encodeURIComponent(resultJson.chat_id);
        return null;
    }

    var uploadedDocuments = Array.isArray(resultJson.uploaded_documents) ? resultJson.uploaded_documents : [];
    var serverDoc = uploadedDocuments.length ? uploadedDocuments[0] : null;
    if (!serverDoc && resultJson && typeof resultJson.document === 'object') {
        serverDoc = resultJson.document;
    }
    if (!serverDoc || typeof serverDoc !== 'object' || typeof serverDoc.id === 'undefined') {
        throw new Error('Upload did not return document metadata');
    }

    var docId = parseInt(serverDoc.id, 10);
    if (!Number.isFinite(docId) || docId <= 0) {
        throw new Error('Invalid document id returned for molecule upload');
    }

    var docIsQueued = !!serverDoc.queued;
    var docName = serverDoc.name || displayName;
    var docDisplayName = displayName;
    var docType = serverDoc.type || 'image/png';
    var docContent = dataUrl;
    if (!docIsQueued) {
        var previewDoc = await fetchDocumentPreview(docId);
        if (previewDoc && (previewDoc.image_src || previewDoc.document_content || previewDoc.content)) {
            var previewContent = previewDoc.image_src || previewDoc.document_content || previewDoc.content;
            if (typeof previewContent === 'string' && previewContent.length) {
                docContent = previewContent;
            }
            if (previewDoc.name) {
                docName = previewDoc.name;
            }
        }
    }

    var docRecord = {
        document_id: docId,
        id: docId,
        document_name: docDisplayName,
        document_type: docType,
        document_source: 'image',
        source: 'image',
        document_ready: docIsQueued ? 0 : 1,
        document_deleted: 0,
        document_token_length: 0,
        enabled: true,
        was_enabled: true,
        document_text: docContent,
        document_content: docContent,
        meta: {
            smiles: smilesToken,
            canonical: effectiveCanonical
        }
    };

    if (typeof window.documentsLength === 'number') {
        window.documentsLength += 1;
    } else {
        window.documentsLength = 1;
    }

    var effectiveChatId = chatIdentifier || (resultJson && resultJson.chat_id) || chatId || '';
    if (effectiveChatId) {
        window.chatDocumentsByChatId = window.chatDocumentsByChatId || {};
        window.chatDocumentsByChatId[effectiveChatId] = window.chatDocumentsByChatId[effectiveChatId] || {};
        window.chatDocumentsByChatId[effectiveChatId][docId] = docRecord;
    }

    if (typeof fetchAndUpdateChatTitles === 'function') {
        try {
            var currentSearch = $('#search-input').length ? $('#search-input').val() : '';
            fetchAndUpdateChatTitles(currentSearch || '', false);
        } catch (err) {
            console.warn('Unable to refresh document list after SMILES upload', err);
        }
    }

    return docRecord;
}

function filterPromptDocumentsForSend(docs) {
    if (!Array.isArray(docs)) {
        return [];
    }
    return docs.filter(function (doc) {
        if (!doc || typeof doc !== 'object') {
            return false;
        }
        var docType = (doc.document_type || doc.type || '').toLowerCase();
        if (docType.indexOf('image/') === 0) {
            var inlineData = doc.document_text || doc.document_content || '';
            if (typeof inlineData !== 'string' || inlineData.indexOf('data:image/') !== 0 || inlineData.length <= 20) {
                console.warn('Skipping image attachment lacking inline data payload', doc);
                return false;
            }
        }
        return true;
    });
}

async function preSendSmilesConfirm(draftText) {
    var result = {
        text: draftText,
        attachments: [],
        abortSend: false
    };

    if (!draftText || typeof draftText !== 'string') {
        return result;
    }

    if (window.appFeatures && window.appFeatures.smiles_support === false) {
        return result;
    }

    var extraction = extractSmilesCommands(draftText);
    var commandTokens = extraction.tokens || [];
    if (!commandTokens.length) {
        return result;
    }

    smilesClientDebug('smiles_command_detected', { tokens: commandTokens, count: commandTokens.length });

    var confirmMessage = 'Detected /smiles command for:\n\n' + commandTokens.join('\n') + '\n\nGenerate molecule image attachments?';
    if (!window.confirm(confirmMessage)) {
        smilesClientDebug('smiles_user_declined', { tokens: commandTokens });
        return result;
    }
    smilesClientDebug('smiles_user_confirmed', { tokens: commandTokens });

    var attachments = [];

    for (var idx = 0; idx < commandTokens.length; idx++) {
        var token = commandTokens[idx];
        smilesClientDebug('smiles_token_start', { token: token, index: idx });

        var canonical = token;
        try {
            var canonicalResponse = await postJSON('chem_canonicalize.php', { smiles: token });
            if (canonicalResponse && canonicalResponse.ok && canonicalResponse.canonical) {
                canonical = canonicalResponse.canonical;
            }
            smilesClientDebug('smiles_canonicalized', { token: token, canonical: canonical, via: canonicalResponse ? canonicalResponse.via : null, index: idx });
        } catch (err) {
            console.warn('SMILES canonicalization failed; using original token', err);
            smilesClientDebug('smiles_canonicalize_error', { token: token, message: err && err.message ? err.message : String(err), index: idx });
        }

        var renderResponse;
        try {
            renderResponse = await postJSON('chem_render_png.php', { smiles: token });
            smilesClientDebug('smiles_render_response', {
                token: token,
                ok: !!(renderResponse && renderResponse.ok),
                via: renderResponse ? renderResponse.via : null,
                png_url: renderResponse ? renderResponse.png_url : null,
                index: idx
            });
        } catch (err) {
            alert('Unable to render one of the molecule previews. The message will be sent without new attachments.');
            smilesClientDebug('smiles_render_error', { token: token, message: err && err.message ? err.message : String(err), index: idx });
            return result;
        }

        if (!renderResponse || !renderResponse.ok || !renderResponse.png_url) {
            alert('Unable to render one of the molecule previews. The message will be sent without new attachments.');
            smilesClientDebug('smiles_render_invalid', { token: token, response: renderResponse || null, index: idx });
            return result;
        }

        var dataUrl;
        try {
            dataUrl = await fetchPngAsDataUrl(renderResponse.png_url);
            smilesClientDebug('smiles_image_fetched', {
                token: token,
                png_url: renderResponse.png_url,
                data_url_length: dataUrl ? dataUrl.length : 0,
                index: idx
            });
        } catch (err) {
            alert('Unable to fetch one of the molecule images. The message will be sent without new attachments.');
            smilesClientDebug('smiles_fetch_image_error', { token: token, message: err && err.message ? err.message : String(err), index: idx });
            return result;
        }

        var attachmentRecord = null;
        try {
            attachmentRecord = await uploadSmilesImage(dataUrl, token, canonical);
            smilesClientDebug('smiles_upload_success', {
                token: token,
                canonical: canonical,
                document_id: attachmentRecord ? attachmentRecord.document_id : null,
                index: idx
            });
        } catch (err) {
            console.error('uploadSmilesImage failed', err);
            alert('Unable to attach one of the molecule images. The message will be sent without new attachments.');
            smilesClientDebug('smiles_upload_error', { token: token, message: err && err.message ? err.message : String(err), index: idx });
            return result;
        }

        if (!attachmentRecord) {
            result.abortSend = true;
            return result;
        }

        attachments.push(attachmentRecord);
    }

    result.attachments = attachments;
    smilesClientDebug('smiles_ready', {
        tokens: commandTokens,
        attachment_document_ids: attachments.map(function (att) { return att ? att.document_id : null; })
    });
    return result;
}

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

function applyWorkflowUiState(isWorkflow) {
    var messageForm = document.getElementById('messageForm');
    var modelSelect = document.getElementById('modelSelectButton');
    var temperatureForm = document.getElementById('temperature_select');
    var reasoningForm = document.getElementById('reasoning_effort_select');
    var verbosityForm = document.getElementById('verbosity_select');
    var mainColTop = document.querySelector('.maincol-top');

    if (messageForm) {
        messageForm.style.display = isWorkflow ? 'none' : 'block';
    }
    if (modelSelect) {
        modelSelect.style.display = isWorkflow ? 'none' : 'inline-block';
    }
    if (temperatureForm) {
        temperatureForm.style.display = isWorkflow ? 'none' : 'inline-block';
    }
    if (reasoningForm) {
        reasoningForm.style.display = isWorkflow ? 'none' : 'inline-block';
    }
    if (verbosityForm) {
        verbosityForm.style.display = isWorkflow ? 'none' : 'inline-block';
    }
    if (mainColTop) {
        mainColTop.style.height = isWorkflow ? 'calc(100vh - 140px)' : 'calc(100vh - 240px)';
    }

    window.workflowUiActive = !!isWorkflow;
}
window.applyWorkflowUiState = applyWorkflowUiState;

function initChatInterface() {
    searchingIndicator = document.getElementById('searching-indicator');
    chatTitlesContainer = document.querySelector('.chat-titles-container');

    if (typeof updatePlaceholder === 'function') {
        updatePlaceholder();
    }

    initializeDocumentProcessingElements();

    if (window.isWorkflowFlow) {
        applyWorkflowUiState(true);
    }

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

async function handleMessageSubmit(event) {
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

    var smilesPrepResult = { text: sanitizedMessageContent, attachments: [], abortSend: false };
    try {
        smilesPrepResult = await preSendSmilesConfirm(sanitizedMessageContent);
    } catch (err) {
        console.error('preSendSmilesConfirm error', err);
    }

    if (smilesPrepResult && smilesPrepResult.abortSend) {
        return;
    }

    if (smilesPrepResult && typeof smilesPrepResult.text === 'string') {
        sanitizedMessageContent = smilesPrepResult.text;
    }

    var smilesAttachments = (smilesPrepResult && Array.isArray(smilesPrepResult.attachments))
        ? smilesPrepResult.attachments
        : [];

    storePromptHistoryEntry(chatId, sanitizedMessageContent);

    var messageContent = base64EncodeUnicode(sanitizedMessageContent);
    var exchangeType = $('#exchange_type').val();
    var customConfigVal = $('#custom_config').val();
    var promptDocsSnapshot = collectCurrentPromptDocuments();

    if (smilesAttachments && smilesAttachments.length) {
        var seenDocIds = {};
        for (var i = 0; i < promptDocsSnapshot.length; i++) {
            var docIdExisting = promptDocsSnapshot[i] && promptDocsSnapshot[i].document_id;
            if (docIdExisting !== undefined && docIdExisting !== null) {
                seenDocIds[String(docIdExisting)] = true;
            }
        }

        smilesAttachments.forEach(function (attachment) {
            if (!attachment || typeof attachment !== 'object') {
                return;
            }
            var docClone = Object.assign({}, attachment);
            if (docClone.document_id !== undefined && docClone.document_id !== null) {
                docClone.document_id = parseInt(docClone.document_id, 10);
                if (!Number.isFinite(docClone.document_id)) {
                    docClone.document_id = attachment.document_id;
                }
            }
            if (docClone.document_id !== undefined && docClone.document_id !== null) {
                var key = String(docClone.document_id);
                if (seenDocIds[key]) {
                    for (var j = 0; j < promptDocsSnapshot.length; j++) {
                        var existing = promptDocsSnapshot[j];
                        if (existing && String(existing.document_id) === key) {
                            promptDocsSnapshot[j] = Object.assign({}, existing, docClone);
                            break;
                        }
                    }
                } else {
                    promptDocsSnapshot.push(docClone);
                    seenDocIds[key] = true;
                }
            } else {
                promptDocsSnapshot.push(docClone);
            }
        });
    }

    promptDocsSnapshot = filterPromptDocumentsForSend(promptDocsSnapshot);

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
    applyWorkflowUiState(exchangeType === 'workflow');

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
    var workflowDetected = !!window.workflowUiActive || !!window.isWorkflowFlow;

    Object.values(chatMessages || {}).forEach(function (message) {
        var exchangeType = message.exchange_type || 'chat';
        if (exchangeType === 'workflow') {
            workflowDetected = true;
        }
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

    var exchangeTypeInput = document.getElementById('exchange_type');
    if (exchangeTypeInput) {
        exchangeTypeInput.value = workflowDetected ? 'workflow' : 'chat';
    }
    if (typeof applyWorkflowUiState === 'function') {
        applyWorkflowUiState(workflowDetected);
    }

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
