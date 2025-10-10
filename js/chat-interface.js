var chatContainer;
var printChatTitle;
var searchingIndicator;
var chatTitlesContainer;
var popup;
var docProcessingBanner;
var docProcessingMessageEl;
var docProcessingFilesEl;
var docProcessingTimerEl;
var docProcessingEstimateEl;
var docProcessingCancelEl;
var originalMessagePlaceholder = '';

var PROCESSING_STORAGE_KEY = 'chat_processing_jobs';
var speechEnabled = typeof window !== 'undefined'
    && 'speechSynthesis' in window
    && typeof window.SpeechSynthesisUtterance !== 'undefined';
var activeUtterance = null;
var activeSpeakButton = null;

var docProcessingState = {
    active: false,
    docIds: [],
    nameMap: {},
    callbacks: [],
    startTime: null,
    pollInterval: null,
    timerInterval: null,
    estimatedTotalSec: null,
    estimateMeta: null
};

window.isDocumentProcessing = false;

function waitForImagesToLoad(container, callback) {
    const images = container.find('img');
    let remaining = images.length;

    if (remaining === 0) {
        callback(); // No images to wait for
        return;
    }

    images.each(function () {
        if (this.complete) {
            // Image is already loaded (cached)
            remaining--;
            if (remaining === 0) callback();
        } else {
            // Wait for the image to load
            $(this).on('load error', function () {
                remaining--;
                if (remaining === 0) callback();
            });
        }
    });
}

let scrollTimeout;
function debounceScroll() {
    clearTimeout(scrollTimeout);
    scrollTimeout = setTimeout(scrollToBottom, 100); // Adjust as needed
}

function scrollToBottom() {
    chatContainer.scrollTop(chatContainer.prop("scrollHeight"));
}

function readProcessingStorage() {
    try {
        var raw = localStorage.getItem(PROCESSING_STORAGE_KEY);
        if (!raw) {
            return {};
        }
        var data = JSON.parse(raw);
        return (data && typeof data === 'object') ? data : {};
    } catch (err) {
        console.warn('Unable to read processing storage', err);
        return {};
    }
}

function writeProcessingStorage(data) {
    try {
        localStorage.setItem(PROCESSING_STORAGE_KEY, JSON.stringify(data || {}));
    } catch (err) {
        console.warn('Unable to persist processing storage', err);
    }
}

function getStoredProcessingState(chatId) {
    if (!chatId) {
        return null;
    }
    var store = readProcessingStorage();
    return store && store[chatId] ? store[chatId] : null;
}

function updateStoredProcessingState(chatId, state) {
    var store = readProcessingStorage();
    if (!chatId) {
        return;
    }
    if (!state) {
        delete store[chatId];
    } else {
        store[chatId] = state;
    }
    writeProcessingStorage(store);
}

function persistProcessingState() {
    if (!chatId) {
        return;
    }

    if (!docProcessingState.active || !docProcessingState.docIds.length) {
        updateStoredProcessingState(chatId, null);
        return;
    }

    var snapshot = {
        docIds: docProcessingState.docIds.slice(),
        names: Object.assign({}, docProcessingState.nameMap || {}),
        startTime: docProcessingState.startTime,
        estimatedSeconds: docProcessingState.estimatedTotalSec,
        estimateMeta: docProcessingState.estimateMeta,
        updatedAt: Date.now()
    };

    updateStoredProcessingState(chatId, snapshot);
}

function initializeDocumentProcessingElements() {
    docProcessingBanner     = $('#doc-processing-banner');
    docProcessingMessageEl  = $('#doc-processing-message');
    docProcessingFilesEl    = $('#doc-processing-files');
    docProcessingTimerEl    = $('#doc-processing-timer');
    docProcessingEstimateEl = $('#doc-processing-estimate');
    docProcessingCancelEl   = $('#doc-processing-cancel');

    if (docProcessingCancelEl && docProcessingCancelEl.length) {
        docProcessingCancelEl.off('click.doc-cancel').on('click.doc-cancel', handleProcessingCancel);
    }

    updateProcessingControls();
}

function setMessageFormEnabled(enabled) {
    var userMessageInput = $('#userMessage');
    var submitButton     = $('#messageForm .submit-button');
    var uploadButton     = $('.upload-button');

    if (!originalMessagePlaceholder) {
        originalMessagePlaceholder = userMessageInput.attr('placeholder') || '';
    }

    if (enabled) {
        userMessageInput.prop('disabled', false)
            .attr('placeholder', originalMessagePlaceholder);
        submitButton.prop('disabled', false);
        uploadButton.prop('disabled', false);
    } else {
        userMessageInput.prop('disabled', true)
            .attr('placeholder', 'Processing uploaded documents…');
        submitButton.prop('disabled', true);
        uploadButton.prop('disabled', true);
    }
}

function formatDocumentNameSummary(names) {
    if (!Array.isArray(names)) {
        return '';
    }
    var filtered = names.filter(function(name) { return !!name; });
    if (filtered.length === 0) {
        return '';
    }
    if (filtered.length === 1) {
        return filtered[0];
    }
    if (filtered.length === 2) {
        return filtered[0] + ' and ' + filtered[1];
    }
    return filtered[0] + ', ' + filtered[1] + ' and ' + (filtered.length - 2) + ' more';
}

function formatElapsedClock(seconds) {
    var sec = Math.max(0, Math.floor(seconds || 0));
    var minutes = Math.floor(sec / 60);
    var remaining = sec % 60;
    return String(minutes).padStart(2, '0') + ':' + String(remaining).padStart(2, '0');
}

function formatDurationHuman(seconds) {
    if (!Number.isFinite(seconds) || seconds <= 0) {
        return '';
    }
    var total = Math.max(0, Math.round(seconds));
    var hours = Math.floor(total / 3600);
    var minutes = Math.floor((total % 3600) / 60);
    var secs = total % 60;
    var parts = [];
    if (hours) {
        parts.push(hours + 'h');
    }
    if (minutes) {
        parts.push(minutes + 'm');
    }
    if (!hours && (secs || parts.length === 0)) {
        parts.push(secs + 's');
    }
    return parts.join(' ');
}

function updateProcessingControls() {
    if (!docProcessingCancelEl || !docProcessingCancelEl.length) {
        return;
    }

    if (docProcessingState.active && docProcessingState.docIds.length) {
        docProcessingCancelEl.prop('disabled', false).show();
    } else {
        docProcessingCancelEl.prop('disabled', true).hide();
    }
}

function handleProcessingCancel(event) {
    if (event) {
        event.preventDefault();
    }

    if (!docProcessingState.docIds.length) {
        return;
    }

    if (!confirm('Cancel processing for the pending document(s)?')) {
        return;
    }

    var ids = docProcessingState.docIds.slice();
    if (docProcessingCancelEl && docProcessingCancelEl.length) {
        docProcessingCancelEl.prop('disabled', true);
    }

    cancelProcessingDocuments(ids, chatId)
        .catch(function(err) {
            console.error('Failed to cancel processing:', err);
            alert('Unable to cancel the upload. Please try again.');
        })
        .finally(function() {
            updateProcessingControls();
        });
}

function cancelProcessingDocuments(docIds, targetChatId) {
    targetChatId = targetChatId || chatId;
    if (!Array.isArray(docIds) || !docIds.length) {
        return Promise.resolve({ canceled_ids: [] });
    }

    var payload = {
        chat_id: targetChatId,
        document_ids: docIds
    };

    return fetch('cancel_document.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify(payload)
    })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        })
        .then(function(data) {
            var canceled = Array.isArray(data.canceled_ids)
                ? data.canceled_ids.map(function(id) { return parseInt(id, 10); }).filter(function(id) { return !Number.isNaN(id); })
                : [];
            var newImages = Array.isArray(data.new_images)
                ? data.new_images.map(function(entry) {
                    if (!entry) return null;
                    return {
                        id: parseInt(entry.id, 10),
                        name: entry.name || ''
                    };
                }).filter(function(entry) {
                    return entry && !Number.isNaN(entry.id) && entry.id > 0;
                })
                : [];

            if (canceled.length) {
                if (targetChatId === chatId) {
                    removeProcessingDocs(canceled, newImages);
                } else {
                    removeProcessingDocsFromStorage(targetChatId, canceled);
                }
            }

            fetchAndUpdateChatTitles($('#search-input').val(), false);
            return data;
        });
}

function isDocumentProcessingForChat(targetChatId, docId) {
    var numericId = parseInt(docId, 10);
    if (!isFinite(numericId) || numericId <= 0) {
        return false;
    }

    if (targetChatId && targetChatId === chatId) {
        if (docProcessingState.docIds.indexOf(numericId) !== -1) {
            return true;
        }
    }

    var stored = getStoredProcessingState(targetChatId);
    if (stored && Array.isArray(stored.docIds)) {
        return stored.docIds.some(function(id) {
            return parseInt(id, 10) === numericId;
        });
    }

    return false;
}

function removeProcessingDocs(docIds, replaceWithImageIds) {
    replaceWithImageIds = replaceWithImageIds || [];
    if (!Array.isArray(docIds) || !docIds.length) {
        persistProcessingState();
        updateProcessingControls();
        return;
    }

    var idSet = new Set(docIds.map(function(id) { return parseInt(id, 10); }).filter(function(id) { return !Number.isNaN(id); }));
    if (!idSet.size) {
        persistProcessingState();
        updateProcessingControls();
        return;
    }

    docProcessingState.docIds = docProcessingState.docIds.filter(function(id) {
        return !idSet.has(parseInt(id, 10));
    });

    if (!Array.isArray(replaceWithImageIds)) {
        replaceWithImageIds = [];
    }
    replaceWithImageIds.forEach(function(entry) {
        if (!entry) {
            return;
        }
        var imgDocId = parseInt(entry.id, 10);
        var imgName  = entry.name || '';
        if (Number.isNaN(imgDocId) || imgDocId <= 0) {
            return;
        }
        if (docProcessingState.docIds.indexOf(imgDocId) === -1) {
            docProcessingState.docIds.push(imgDocId);
        }
        if (imgName) {
            docProcessingState.nameMap[imgDocId] = imgName;
        }
    });

    Object.keys(docProcessingState.nameMap || {}).forEach(function(key) {
        if (idSet.has(parseInt(key, 10))) {
            delete docProcessingState.nameMap[key];
        }
    });

    persistProcessingState();

    if (!docProcessingState.docIds.length) {
        docProcessingState.callbacks = [];
        finishDocumentProcessing();
    } else {
        renderDocumentProcessingBanner();
        updateDocumentProcessingTimer();
        updateProcessingControls();
    }
}

function removeProcessingDocsFromStorage(targetChatId, docIds) {
    if (!targetChatId || !Array.isArray(docIds) || !docIds.length) {
        return;
    }

    var store = readProcessingStorage();
    var state = store[targetChatId];
    if (!state) {
        return;
    }

    var idSet = new Set(docIds.map(function(id) { return parseInt(id, 10); }).filter(function(id) { return !Number.isNaN(id); }));
    if (!idSet.size) {
        return;
    }

    state.docIds = (state.docIds || []).filter(function(id) {
        return !idSet.has(parseInt(id, 10));
    });

    if (state.names) {
        Object.keys(state.names).forEach(function(key) {
            if (idSet.has(parseInt(key, 10))) {
                delete state.names[key];
            }
        });
    }

    if (!state.docIds.length) {
        delete store[targetChatId];
    } else {
        store[targetChatId] = state;
    }

    writeProcessingStorage(store);
}

function renderDocumentProcessingBanner() {
    if (!docProcessingBanner || docProcessingBanner.length === 0) {
        return;
    }

    var pendingNames = docProcessingState.docIds.map(function(id) {
        return docProcessingState.nameMap[id] || '';
    }).filter(Boolean);

    var docCount = docProcessingState.docIds.length;
    var message = docCount === 1
        ? 'Processing 1 document…'
        : 'Processing ' + docCount + ' documents…';

    docProcessingBanner.addClass('active').css('display', 'flex');

    if (docProcessingMessageEl && docProcessingMessageEl.length) {
        docProcessingMessageEl.text(message);
    }

    if (docProcessingFilesEl && docProcessingFilesEl.length) {
        var summary = formatDocumentNameSummary(pendingNames);
        docProcessingFilesEl.text(summary ? 'Files: ' + summary : '');
    }

    if (docProcessingEstimateEl && docProcessingEstimateEl.length) {
        if (docProcessingState.estimatedTotalSec && docProcessingState.estimatedTotalSec > 0) {
            var estimateText = 'Estimated total: ~' + formatDurationHuman(docProcessingState.estimatedTotalSec);
            var meta = docProcessingState.estimateMeta || {};
            var sourceDescriptions = {
                mime: 'similar uploads',
                global: 'recent uploads',
                mixed: 'upload history',
                default: 'initial baseline'
            };
            var sourceKey = meta.source || null;
            if (sourceKey && sourceDescriptions[sourceKey]) {
                estimateText += ' (' + sourceDescriptions[sourceKey];
                if (meta.total_data_points) {
                    estimateText += ', ' + meta.total_data_points + ' sample' + (meta.total_data_points === 1 ? '' : 's');
                }
                estimateText += ')';
            } else if (meta.total_data_points) {
                estimateText += ' (' + meta.total_data_points + ' samples)';
            }
            docProcessingEstimateEl.text(estimateText);
        } else {
            docProcessingEstimateEl.text('');
        }
    }

    updateProcessingControls();
}

function updateDocumentProcessingTimer() {
    if (!docProcessingTimerEl || docProcessingTimerEl.length === 0) {
        return;
    }

    var elapsedSeconds = 0;
    if (docProcessingState.startTime) {
        elapsedSeconds = Math.max(0, (Date.now() - docProcessingState.startTime) / 1000);
    }

    var text = 'Elapsed: ' + formatElapsedClock(elapsedSeconds);
    if (docProcessingState.estimatedTotalSec && docProcessingState.estimatedTotalSec > 0) {
        text += ' / ~' + formatDurationHuman(docProcessingState.estimatedTotalSec);
    }
    docProcessingTimerEl.text(text);
}

function pollDocumentProcessingStatus() {
    if (!docProcessingState.active || docProcessingState.docIds.length === 0) {
        finishDocumentProcessing();
        return;
    }

    fetch('document_status.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin',
        cache: 'no-store',
        body: JSON.stringify({ document_ids: docProcessingState.docIds })
    })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        })
        .then(function(data) {
            if (!data || !Array.isArray(data.documents)) {
                return;
            }

            var completedIds = [];
            data.documents.forEach(function(entry) {
                var docId = parseInt(entry.document_id, 10);
                if (!Number.isNaN(docId) && entry.ready) {
                    completedIds.push(docId);
                }
            });

            if (completedIds.length) {
                docProcessingState.docIds = docProcessingState.docIds.filter(function(id) {
                    if (completedIds.indexOf(id) !== -1) {
                        delete docProcessingState.nameMap[id];
                        return false;
                    }
                    return true;
                });
                persistProcessingState();
            }

            if (docProcessingState.docIds.length === 0 || data.all_ready) {
                finishDocumentProcessing();
            } else {
                renderDocumentProcessingBanner();
            }
        })
        .catch(function(error) {
            if (error && error.name === 'AbortError') {
                return;
            }
            if (error && (error.message === 'Load failed' || error.message === 'NetworkError when attempting to fetch resource.' || error.message === 'Failed to fetch')) {
                console.warn('Document status poll interrupted:', error.message);
            } else {
                console.error('Document status check failed:', error);
            }
        });
}

function finishDocumentProcessing() {
    if (!docProcessingState.active) {
        return;
    }

    if (docProcessingState.pollInterval) {
        clearInterval(docProcessingState.pollInterval);
        docProcessingState.pollInterval = null;
    }

    if (docProcessingState.timerInterval) {
        clearInterval(docProcessingState.timerInterval);
        docProcessingState.timerInterval = null;
    }

    updateDocumentProcessingTimer();

    if (docProcessingBanner && docProcessingBanner.length) {
        docProcessingMessageEl.text('Documents ready');
        docProcessingFilesEl.text('');
        if (docProcessingEstimateEl && docProcessingEstimateEl.length) {
            docProcessingEstimateEl.text('');
        }
        setTimeout(function() {
            docProcessingBanner.removeClass('active').fadeOut(200);
        }, 800);
    }

    setMessageFormEnabled(true);
    window.isDocumentProcessing = false;

    var callbacks = docProcessingState.callbacks.slice();

    docProcessingState.active = false;
    docProcessingState.docIds = [];
    docProcessingState.nameMap = {};
    docProcessingState.callbacks = [];
    docProcessingState.startTime = null;
   docProcessingState.estimatedTotalSec = null;
   docProcessingState.estimateMeta = null;

    persistProcessingState();
    updateProcessingControls();

    if (typeof fetchAndUpdateChatTitles === 'function') {
        var currentSearch = $('#search-input').val();
        fetchAndUpdateChatTitles(currentSearch, false);
    }

    callbacks.forEach(function(callback) {
        if (typeof callback === 'function') {
            try {
                callback();
            } catch (cbError) {
                console.error('Deferred submission failed:', cbError);
            }
        }
    });
}

function startDocumentProcessingWatch(documents, options) {
    options = options || {};

    if (!Array.isArray(documents) || documents.length === 0) {
        if (typeof options.onReady === 'function') {
            options.onReady();
        }
        return;
    }

    if (!docProcessingBanner || docProcessingBanner.length === 0) {
        initializeDocumentProcessingElements();
    }

    var resumeStart = (typeof options.startTime === 'number' && options.startTime > 0) ? options.startTime : null;

    if (!docProcessingState.active) {
        docProcessingState.active = true;
        docProcessingState.docIds = [];
        docProcessingState.nameMap = {};
        docProcessingState.callbacks = [];
        docProcessingState.startTime = resumeStart || Date.now();
        docProcessingState.estimatedTotalSec = null;
        docProcessingState.estimateMeta = null;
    } else if (!docProcessingState.startTime) {
        docProcessingState.startTime = resumeStart || Date.now();
    } else if (resumeStart && resumeStart < docProcessingState.startTime) {
        docProcessingState.startTime = resumeStart;
    }

    if (typeof options.estimatedSeconds === 'number' && options.estimatedSeconds > 0) {
        if (docProcessingState.estimatedTotalSec && docProcessingState.active) {
            docProcessingState.estimatedTotalSec += options.estimatedSeconds;
        } else {
            docProcessingState.estimatedTotalSec = options.estimatedSeconds;
        }
    }

    if (options.estimateMeta) {
        docProcessingState.estimateMeta = options.estimateMeta;
    }

    documents.forEach(function(doc) {
        if (!doc) {
            return;
        }
        var docId = parseInt(doc.id, 10);
        if (Number.isNaN(docId) || docId <= 0) {
            return;
        }
        if (docProcessingState.docIds.indexOf(docId) === -1) {
            docProcessingState.docIds.push(docId);
        }
        if (doc.name) {
            docProcessingState.nameMap[docId] = doc.name;
        }
    });

    if (options.docNames && Array.isArray(options.docNames)) {
        options.docNames.forEach(function(name, index) {
            var doc = documents[index];
            if (!doc) {
                return;
            }
            var docId = parseInt(doc.id, 10);
            if (!Number.isNaN(docId) && docId > 0 && name) {
                docProcessingState.nameMap[docId] = name;
            }
        });
    }

    if (typeof options.onReady === 'function' && docProcessingState.callbacks.indexOf(options.onReady) === -1) {
        docProcessingState.callbacks.push(options.onReady);
    }

    persistProcessingState();

    renderDocumentProcessingBanner();
    updateDocumentProcessingTimer();
    setMessageFormEnabled(false);
    window.isDocumentProcessing = true;

    pollDocumentProcessingStatus();

    if (docProcessingState.pollInterval) {
        clearInterval(docProcessingState.pollInterval);
    }
    docProcessingState.pollInterval = setInterval(pollDocumentProcessingStatus, 5000);

    if (docProcessingState.timerInterval) {
        clearInterval(docProcessingState.timerInterval);
    }
    docProcessingState.timerInterval = setInterval(updateDocumentProcessingTimer, 1000);
}

function notifyDocumentProcessingInProgress() {
    if (!docProcessingState.active || !docProcessingBanner || docProcessingBanner.length === 0) {
        return;
    }
    renderDocumentProcessingBanner();
    docProcessingBanner.addClass('pulse');
    setTimeout(function() {
        docProcessingBanner.removeClass('pulse');
    }, 1200);
}

function syncProcessingStateFromServer(chatData) {
    if (!chatId) {
        return;
    }

    if (docProcessingState.active) {
        // Already tracking; ensure persistence is up to date
        persistProcessingState();
        return;
    }

    var serverChat = null;
    if (chatData) {
        if (Array.isArray(chatData)) {
            serverChat = chatData.find(function(item) { return item && item.id === chatId; });
        } else if (chatData[chatId]) {
            serverChat = chatData[chatId];
        }
    }

    var storedState = getStoredProcessingState(chatId);
    if (!serverChat && !storedState) {
        return;
    }

    if (storedState && storedState.docIds && storedState.docIds.length) {
        var resumeDocs = storedState.docIds.map(function(id) {
            var strId = String(id);
            var name = '';
            if (storedState.names && storedState.names[strId]) {
                name = storedState.names[strId];
            } else if (serverChat && serverChat.document && serverChat.document[strId]) {
                name = serverChat.document[strId].name || '';
            }
            var type = '';
            var readyFromServer = false;
            if (serverChat && serverChat.document && serverChat.document[strId]) {
                var docMeta = serverChat.document[strId];
                type = docMeta.type || '';
                readyFromServer = docMeta.ready === true || docMeta.ready === 1 || docMeta.ready === '1';
            }
            return { id: id, name: name, type: type, ready: readyFromServer };
        });

        if (serverChat && serverChat.document) {
            resumeDocs = resumeDocs.filter(function(doc) {
                if (!doc) {
                    return false;
                }
                if (doc.ready) {
                    return false;
                }
                if (doc.type && doc.type.indexOf('image/') === 0) {
                    return false;
                }
                return true;
            });
        }

        if (!resumeDocs.length) {
            removeProcessingDocsFromStorage(chatId, storedState.docIds || []);
            return;
        }

        startDocumentProcessingWatch(resumeDocs, {
            docNames: resumeDocs.map(function(d) { return d.name; }),
            estimatedSeconds: storedState.estimatedSeconds || null,
            estimateMeta: storedState.estimateMeta || null,
            startTime: storedState.startTime || null
        });
        return;
    }

    if (!serverChat || !serverChat.document) {
        return;
    }

    var pendingDocs = [];
    Object.entries(serverChat.document).forEach(function([id, doc]) {
        if (!doc) {
            return;
        }
        var ready = doc.ready === true || doc.ready === 1 || doc.ready === '1';
        if (!ready) {
            pendingDocs.push({
                id: parseInt(id, 10),
                name: doc.name || '',
                type: doc.type || ''
            });
        }
    });

    if (pendingDocs.length) {
        startDocumentProcessingWatch(pendingDocs, {
            docNames: pendingDocs.map(function(d) { return d.name; })
        });
    }
}

$(document).ready(function() {

    searchingIndicator = document.getElementById('searching-indicator');
    chatTitlesContainer = document.querySelector('.chat-titles-container');
    updatePlaceholder(); // update the placeholder text in the message window

    initializeDocumentProcessingElements();
    originalMessagePlaceholder = $('#userMessage').attr('placeholder') || originalMessagePlaceholder;

    // Run on page load
    adjustChatTitlesHeight();

    // Display all the chat titles
    fetchAndUpdateChatTitles(search_term,0);

    chatContainer = $(".chat-container");
    var userMessage = $("#userMessage");

    // Set focus on the message input
    userMessage.focus();

    // Initially load messages
    loadMessages();

    // Event listener for the Enter key press
    userMessage.on("keydown", function (e) {
        if (e.keyCode === 13 && !e.shiftKey) {
            e.preventDefault();
            $('#messageForm').submit();
        }
    });

    $(document).on('click', '.edit-confirm-icon', function () {
        var chatId = $(this).parent().prev().attr('id').split('-')[2];
        submitEdit(chatId);
    });

    // Event listener for form submission
    $('#messageForm').submit(function(e) {
        e.preventDefault();

        if (window.isDocumentProcessing) {
            notifyDocumentProcessingInProgress();
            return;
        }

        var rawMessageContent = userMessage.val().trim();
        var sanitizedMessageContent = replaceNonAsciiCharacters(rawMessageContent);

        // Optionally, show a warning if the message was modified
        /*
        if (sanitizedMessageContent !== rawMessageContent) {
            if (!confirm("Your message contains some special characters that might cause issues. Click OK to send the modified message or Cancel to edit your message.")) {
                return;
            }
        }
        */

        var messageContent = base64EncodeUnicode(sanitizedMessageContent); // Encode in Base64 UTF-8

        // Before your AJAX call, log the values from the hidden inputs.
        var exchange_type = $('#exchange_type').val();
        var customConfigVal = $('#custom_config').val();
        console.log("Submitting form with exchange_type:", exchange_type, "and custom_config:", customConfigVal);

        // Display the user message (prompt) immediately after submission
        if (sanitizedMessageContent !== "") {
            var userMessageDecoded = base64DecodeUnicode(messageContent);
            var sanitizedPrompt = formatCodeBlocks(userMessageDecoded);

            let icon = 'user.png';
            if (exchange_type == 'workflow')  {
                icon = 'gear_icon.png';

                // Hide specific elements
                document.getElementById('messageForm').style.display = 'none';
                document.getElementById('modelSelectButton').style.display = 'none';
                document.getElementById('temperature_select').style.display = 'none';

                // Alter the .maincol-top element
                let mainColTopElement = document.querySelector('.maincol-top');
                if (mainColTopElement) {
                    mainColTopElement.style.height = 'calc(100vh - 140px)';
                }
            } else if (exchange_type == 'chat') {
                icon = 'user.png';

                // Show specific elements (if needed for 'chat' type)
                document.getElementById('messageForm').style.display = 'block';
                document.getElementById('modelSelectButton').style.display = 'inline-block';
                const tempForm = document.getElementById('temperature_select');
                if (tempForm) {
                    tempForm.style.display = 'block';
                }


                // Revert the .maincol-top element changes (if needed for 'chat' type)
                let mainColTopElement = document.querySelector('.maincol-top');
                if (mainColTopElement) {
                    mainColTopElement.style.height = 'calc(100vh - 240px)';
                }
            }



            var userMessageElement = $('<div class="message user-message"></div>').html(sanitizedPrompt);
            userMessageElement.prepend('<img src="images/'+icon+'" class="user-icon" alt="User icon">');
            chatContainer.append(userMessageElement);

            // Apply syntax highlighting to code within the user message
            userMessageElement.find('pre code').each(function(i, block) {
                hljs.highlightElement(block);
            });

            // AJAX IN THE IMAGES FROM THE DATABASE
            if (deployment != 'azure-dall-e-3') fetchUserImages(chatId, userMessageElement);

            // Apply MathJax typesetting to the user message
            if (window.MathJax) {
                MathJax.typesetPromise([userMessageElement[0]]).then(function () {
                    debounceScroll();
                }).catch(function(err) {
                    console.error("MathJax typeset failed: " + err.message);
                    debounceScroll();
                });
            } else {
                debounceScroll();
            }

            // Scroll to the bottom of the chat container
            // (debounceScroll is already called in the MathJax success/error handlers)

            // Clear the textarea and localStorage right after form submission
            userMessage.val("");
            localStorage.removeItem('chatDraft_' + chatId);
        }


        if (messageContent !== "") {
            // **Retrieve the selected deployment (model)**
            //var deployment = $('#model_select select[name="model"]').val();
            
            $.ajax({
                type: "POST",
                url: "ajax_handler.php",
                data: {
                    message: messageContent,
                    chat_id: chatId,
                    user: user,
                    deployment: deployment,  // **Include the deployment here**
                    exchange_type: exchange_type, 
                    custom_config: customConfigVal 

                },

                beforeSend: function() {
                    $('.waiting-indicator').show();
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    $('.waiting-indicator').hide();
                    console.error('Prompt submission failed', textStatus, errorThrown, jqXHR);
                    recordClientEvent('ajax_prompt_failure', {
                        text_status: textStatus,
                        error: errorThrown,
                        status: jqXHR ? jqXHR.status : null,
                        ready_state: jqXHR ? jqXHR.readyState : null,
                        response_length: jqXHR && jqXHR.responseText ? jqXHR.responseText.length : null,
                        response_preview: jqXHR && jqXHR.responseText ? jqXHR.responseText.slice(0, 500) : null,
                        message_preview: sanitizedPrompt ? sanitizedPrompt.slice(0, 120) : null,
                        message_length: sanitizedPrompt ? sanitizedPrompt.length : null
                    });
                },

           
success : function (response, textStatus, jqXHR) {

    $('.waiting-indicator').hide();

    /* ---------- diagnostics ---------- */
    console.groupCollapsed('%cAJAX success from ajax_handler.php','font-weight:bold');
    console.log('Status / textStatus:', jqXHR.status, textStatus);
    console.log('Raw response >>>', response);
    console.groupEnd();

    let json;
    try {
        json = JSON.parse(response);
    } catch (parseErr) {
        console.error('❌ JSON.parse failed:', parseErr);
        recordClientEvent('ajax_response_parse_failure', {
            error: parseErr.message || String(parseErr),
            status: jqXHR ? jqXHR.status : null,
            text_status: textStatus,
            content_type: jqXHR ? jqXHR.getResponseHeader('Content-Type') : null,
            response_preview: typeof response === 'string' ? response.slice(0, 500) : null,
            message_preview: sanitizedPrompt ? sanitizedPrompt.slice(0, 120) : null,
            message_length: sanitizedPrompt ? sanitizedPrompt.length : null
        });
        alert('We were unable to process the assistant response. Please retry.');
        return;               // bail – the rest of the handler needs real JSON
    }

    /* ---------- sanity-checks ---------- */
    const {
        eid,
        gpt_response      : rawResponse,
        image_gen_name    : imageGenName,
        deployment        : deployment,
        error,
        new_chat_id,
        code
    } = json;

    if (code === 'session_expired') {
        recordClientEvent('ajax_session_expired', {
            status: jqXHR ? jqXHR.status : null,
            text_status: textStatus,
            message_preview: sanitizedPrompt ? sanitizedPrompt.slice(0, 120) : null
        });
        alert('Your session has expired. Please refresh the page to continue.');
        window.location.href = window.location.href;
        return;
    }

    if (error) {
        alert('Error: ' + rawResponse);
        return;
    }

    console.table({
        eid, deployment, imageGenName,
        'rawResponse type' : typeof rawResponse
    });

    /* ---------- redirect on new chat ---------- */
    if (new_chat_id) {
        window.location.href = `/${application_path}/${new_chat_id}`;
        return;
    }

    /* ---------- build assistant message ---------- */
    const assistantMessageElement = $(
        '<div class="message assistant-message" style="margin-bottom:30px;"></div>'
    );

    // avatar
    assistantMessageElement.prepend(`
        <img src="images/${deployments[deployment].image}"
             alt="${deployments[deployment].image_alt}"
             class="openai-icon">
    `);

    /* ---------- branch: IMAGE vs TEXT ---------- */
    if (imageGenName) {                    /* ==== IMAGE ==== */

        $('<img>', {
            class : 'image-message',
            src   : `../image_gen/small/${imageGenName}`,
            alt   : 'Generated Image'
        })
        .on('load error', debounceScroll)  // ← event binding *after* creation
        .appendTo(assistantMessageElement);

        addDownloadButton(
            assistantMessageElement,
            `../image_gen/fullsize/${imageGenName}`
        );

    } else if (rawResponse) {              /* ==== TEXT ==== */

        const formattedHTML = formatCodeBlocks(rawResponse);
        assistantMessageElement.append(formattedHTML);
        addCopyButton(assistantMessageElement, rawResponse);
        const plainResponse = $('<div>').html(formattedHTML).text();
        addSpeakButton(assistantMessageElement, plainResponse);
    }

    /* ---------- inject & post-process ---------- */
    chatContainer.append(assistantMessageElement);

    if (!imageGenName) {
        hljs.highlightAll();
        if (window.MathJax) {
            MathJax.typesetPromise([assistantMessageElement[0]])
                   .then(debounceScroll)
                   .catch(err => { console.error(err); debounceScroll(); });
        } else {
            debounceScroll();
        }
    }
/* ---------- refresh left-nav titles ---------- */
/* `search_term` is whatever global you already use for the search box.
   If it might be undefined, fall back to an empty string so the list reloads normally. */
if (typeof fetchAndUpdateChatTitles === 'function') {
    fetchAndUpdateChatTitles(typeof search_term !== 'undefined' ? search_term : '', 0);
}

}
 
            });
        }
    });

    // Function to add the download button
    function addDownloadButton(messageElement, fullImagePath) {
        // Create the download button
        var downloadButton = $(`
            <button class="copy-chat-button" title="Download Full Image" aria-label="Download the full image">
                <span style="font-size:12px;">Download Full Image</span>
                <svg version="1.1" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24">
                    <title>cloud-download</title>
                    <path d="M12.75 20.379v-10.573h-1.5v10.573l-2.432-2.432-1.061 1.061 4.243 4.243 4.243-4.243-1.061-1.061-2.432 2.432z"></path>
                    <path d="M18.75 7.555c0-3.722-3.028-6.75-6.75-6.75s-6.75 3.028-6.75 6.75c-2.485 0-4.5 2.015-4.5 4.5s2.015 4.5 4.5 4.5h3.75v-1.5h-3.75c-1.657 0-3-1.343-3-3s1.343-3 3-3v0h1.5v-1.5c0-2.899 2.351-5.25 5.25-5.25s5.25 2.351 5.25 5.25v0 1.5h1.5c1.657 0 3 1.343 3 3s-1.343 3-3 3v0h-3.75v1.5h3.75c2.485 0 4.5-2.015 4.5-4.5s-2.015-4.5-4.5-4.5v0z"></path>
                </svg>
            </button>
        `);

        // Append the button to the message element
        messageElement.append(downloadButton);

        // Set up the click handler to download the full-size image
        downloadButton.on('click', function() {
            var link = document.createElement('a');
            link.href = fullImagePath;
            link.download = 'GeneratedImage.png';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        });
    }

    // Copy button for text
function addCopyButton(messageElement, rawMessageContent) {
        // Create the copy button without the onclick attribute
        var copyButton = $(`
            <button class="copy-chat-button" title="Copy Raw Reply" aria-label="Copy the current reply to clipboard">
                <span style="font-size:12px;">Copy Raw Reply</span>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                    <path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"></path>
                </svg>
            </button>
        `);

    var controlsWrapper = messageElement.find('.reply-controls');
    if (!controlsWrapper.length) {
        controlsWrapper = $('<div class="reply-controls"></div>').css({
            display: 'flex',
            flexWrap: 'wrap',
            gap: '8px',
            marginTop: '8px'
        });
        messageElement.append(controlsWrapper);
    }

    copyButton.css({
        display: 'inline-flex',
        alignItems: 'center',
        gap: '6px'
    });

    controlsWrapper.append(copyButton);

    // Copy the raw content to clipboard on click
    copyButton.on('click', function() {
        // Use the rawMessageContent directly
        navigator.clipboard.writeText(rawMessageContent).then(function() {
            // Create a subtle popup message
            var popup = $('<span class="copied-chat-popup show">Copied!</span>').css({
                marginLeft: '12px',
                fontSize: '12px'
            });
            copyButton.after(popup);
            setTimeout(function() {
                popup.remove();
            }, 2000);
        }, function(err) {
            console.error('Could not copy text: ', err);
        });
    });
}

    function resetSpeakButton(button) {
        if (!button) {
            return;
        }
        button.attr('aria-label', 'Play audio for this reply');
        button.html(`
            <span style="font-size:12px;">Play Audio</span>
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                <path d="M3 9v6h4l5 5V4L7 9H3z"></path>
                <path d="M14 9.23v5.54c1.19-.69 2-.98 3-.98s2.25.31 3 1.01V9.2c-.75.69-1.75 1.02-3 1.02s-1.81-.28-3-.99z"></path>
            </svg>
        `);
        button.removeClass('speak-playing');
    }

    function setSpeakButtonPlaying(button) {
        button.attr('aria-label', 'Stop audio playback');
        button.html(`
            <span style="font-size:12px;">Stop Audio</span>
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                <path d="M6 5h4v14H6zM14 5h4v14h-4z"></path>
            </svg>
        `);
        button.addClass('speak-playing');
    }

    function addSpeakButton(messageElement, rawMessageContent) {
        if (!speechEnabled) {
            return;
        }

        var plain = (rawMessageContent || '').replace(/\s+/g, ' ').trim();
        if (!plain) {
            return;
        }

        var speakButton = $(`
            <button class="copy-chat-button speak-chat-button" title="Play audio for this reply" aria-label="Play audio for this reply">
                <span style="font-size:12px;">Play Audio</span>
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                <path d="M3 9v6h4l5 5V4L7 9H3z"></path>
                <path d="M14 9.23v5.54c1.19-.69 2-.98 3-.98s2.25.31 3 1.01V9.2c-.75.69-1.75 1.02-3 1.02s-1.81-.28-3-.99z"></path>
            </svg>
        </button>
    `);

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

        speakButton.css({
            display: 'inline-flex',
            alignItems: 'center',
            gap: '6px'
        });

        speakButton.on('click', function () {
            if (!speechEnabled) {
                return;
            }

            var sameButtonActive = activeSpeakButton && activeSpeakButton[0] === speakButton[0];
            if (activeUtterance) {
                window.speechSynthesis.cancel();
                resetSpeakButton(activeSpeakButton);
                activeUtterance = null;
                activeSpeakButton = null;
                if (sameButtonActive) {
                    return;
                }
            }

            var utterance = new window.SpeechSynthesisUtterance(plain.replace(/[*_`]/g, ''));
            utterance.onend = function () {
                if (activeSpeakButton && activeSpeakButton[0] === speakButton[0]) {
                    resetSpeakButton(speakButton);
                    activeUtterance = null;
                    activeSpeakButton = null;
                }
            };
            utterance.onerror = function () {
                if (activeSpeakButton && activeSpeakButton[0] === speakButton[0]) {
                    resetSpeakButton(speakButton);
                    activeUtterance = null;
                    activeSpeakButton = null;
                }
            };

            activeUtterance = utterance;
            activeSpeakButton = speakButton;
            setSpeakButtonPlaying(speakButton);
            window.speechSynthesis.speak(utterance);
        });

        controls.append(speakButton);
    }

    // Load old messages
    function loadMessages() {
        $.ajax({
            url: "get_messages.php",
            data: { chat_id: chatId, user: user },
            dataType: 'json',
            success: function(chatMessages) {
                console.log("this is chatMessages to show we're actually getting them");
                console.log(chatMessages);
                displayMessages(chatMessages);
                //scrollToBottom();
                debounceScroll();
            }
        });
    }

    // Show older chat messages
    function displayMessages(chatMessages) {
        // Since chatMessages is an object, iterate over its values
        Object.values(chatMessages).forEach(function (message) {

            //------------------------------------------------
            // 1) USER PROMPT
            //------------------------------------------------
            // Apply formatCodeBlocks to the user's prompt
            // If message.exchange_type is "workflow", specific elements (#messageForm, #modelSelectButton, #temperature_select) are hidden, 
            // and the height of the .maincol-top element is adjusted.
            // If message.exchange_type is "chat", those elements are shown again, 
            // and the height of the .maincol-top element is reverted to its original value.

            let icon = 'user.png';

            console.log("exchange type = " + message.exchange_type);

            if (message.exchange_type == 'workflow') {
                icon = 'gear_icon.png';
                console.log("CONFIRMED this is a workflow");

                // Hide specific elements
                document.getElementById('messageForm').style.display = 'none';
                document.getElementById('modelSelectButton').style.display = 'none';
                document.getElementById('temperature_select').style.display = 'none';
                $('#exchange_type').val('workflow')

                // Alter the .maincol-top element
                let mainColTopElement = document.querySelector('.maincol-top');
                if (mainColTopElement) {
                    mainColTopElement.style.height = 'calc(100vh - 140px)';
                }
            } else if (message.exchange_type == 'chat') {
                icon = 'user.png';
                console.log("CONFIRMED this is a chat");

                // Show specific elements (if needed for 'chat' type)
                document.getElementById('messageForm').style.display = 'block';
                document.getElementById('modelSelectButton').style.display = 'inline-block';
                const tempForm = document.getElementById('temperature_select');
                if (tempForm) {
                    tempForm.style.display = 'inline-block';
                }

                // Revert the .maincol-top element changes (if needed for 'chat' type)
                let mainColTopElement = document.querySelector('.maincol-top');
                if (mainColTopElement) {
                    mainColTopElement.style.height = 'calc(100vh - 240px)';
                }
            }

            var sanitizedPrompt = formatCodeBlocks(message.prompt);
            var userMessageElement = $('<div class="message user-message" style="position: relative;"></div>').html(sanitizedPrompt);
            userMessageElement.prepend('<img src="images/'+icon+'" class="user-icon" alt="User icon">');

            // --- Add pin icon ---
            var isPinned = message.is_pinned; // Suppose you set this field on your message object
            var pinClass = isPinned ? 'pin-active' : '';
            var pinTitle = isPinned ? "Pinned prompt" : "Pin this prompt";

            // Create the pin button with a label
            var pinButton = $(`
                <button class="pin-chat-button" title="Pin this prompt" aria-label="Pin this prompt" style="margin-left:10px; vertical-align:middle;">
                    <span style="font-size:12px; margin-right:6px;">`+pinTitle+`</span>
                    <img src="images/pin.svg" class="pin-icon" style="width:20px; vertical-align:middle;" alt="Pin icon">
                </button>
            `);

            // Add click handler (for now, just logs)
            pinButton.on('click', function(e) {
                e.stopPropagation();
                pinPrompt(message); // define this function to handle the pin action
            });

            // Append the pin button to the user message element
            userMessageElement.append(pinButton);

            chatContainer.append(userMessageElement);

            //------------------------------------------------
            // 2) ASSISTANT REPLY
            //------------------------------------------------
            var assistantMessageElement = null;
            if (message.deployment && deployments[message.deployment]) {
                assistantMessageElement = $('<div class="message assistant-message"></div>');

                // Prepend the assistant's icon
                var imgSrc = 'images/' + deployments[message.deployment].image;
                var imgAlt = deployments[message.deployment].image_alt;
                assistantMessageElement.prepend('<img src="' + imgSrc + '" alt="' + imgAlt + '" class="openai-icon">');

                chatContainer.append(assistantMessageElement);

                // If there's a text reply, handle it
        if (message.reply) {
            var sanitizedReply = formatCodeBlocks(message.reply);
            assistantMessageElement.append(sanitizedReply);
            addCopyButton(assistantMessageElement, message.reply);
            var plainReply = $('<div>').html(sanitizedReply).text();
            addSpeakButton(assistantMessageElement, plainReply);
        }
            }

            //------------------------------------------------
            // 3) HANDLE GENERATED IMAGE (IF PRESENT)
            //------------------------------------------------
            if (message.image_gen_name) {
                //console.log("I think I have an image gen name");
                var genImg = $('<img>')
                    .attr('class', 'image-message')
                    .attr('src', '../image_gen/small/' + message.image_gen_name)
                    .attr('alt', 'Generated Image')
                    .on('load', function () {
                        //scrollToBottom(); // Scroll after the image loads
                        debounceScroll();
                    });

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

            //------------------------------------------------
            // 4) DOCUMENTS (USER OR ASSISTANT UPLOADED)
            //------------------------------------------------
            if (message.document) {
                // Iterate over each document entry in the document object
                $.each(message.document, function(key, doc) {
                    // Only process if the document is an image type
                    if (doc.document_name && doc.document_text && /^image\//.test(doc.document_type)) {
                        var docImg = $('<img>')
                            .attr('class', 'image-message')
                            .attr('src', doc.document_text)
                            .attr('alt', doc.document_name || '');
                        
                        userMessageElement.append(docImg);
                    }
                });
            }
        });

        // After appending all messages, do syntax highlighting and MathJax
        hljs.highlightAll();
        if (window.MathJax) {
            MathJax.typesetPromise([chatContainer[0]]).then(function() {
                debounceScroll();
            }).catch(function(err) {
                console.error("MathJax typeset failed: " + err.message);
                debounceScroll();
            });
        } else {
            debounceScroll();
        }
    }

    // Identify and format code blocks with triple backticks
    function formatCodeBlocks(reply) {
      /* ---------- 0. LaTeX clean-ups ---------- */
      reply = reply
        .replace(/\\left\s*\[/g, '\\left.')
        .replace(/\\right\s*\]/g, '\\right|')
        .replace(/E\[(.*?)\]/g, 'E($1)')
        .replace(/\\\[([^\]]+)\\\]/g, (_, latex) =>
          `<div class="math-block">$$ ${latex} $$</div>`)
        .replace(/\\\(([^)]+)\\\)/g, (_, latex) =>
          `<span class="math-inline">$ ${latex} $</span>`);

      /* ---------- 1. Extract ```code``` blocks ---------- */
      const codeBlocks = [];
      reply = reply.replace(/```(\w*)\n([\s\S]*?)```/g, (_, lang, code) => {
        const languageClass = lang ? `language-${lang}` : 'plaintext';
        const sanitized = sanitizeString(code);
        const html = `
          <div class="code-block">
            <div class="language-label">${lang || 'code'}</div>
            <button class="copy-button" title="Copy Code" onclick="copyToClipboard(this)">
              <span style="font-size:12px;">Copy Code</span>
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                <path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/>
              </svg>
            </button>
            <pre><code class="${languageClass}">${sanitized}</code></pre>
          </div>`.trim();

        codeBlocks.push(html);
        return `@@CB_${codeBlocks.length - 1}@@`;          // safe placeholder
      });

      /* ---------- 2. Parse markdown ---------- */
      reply = marked.parse(reply, { sanitize: false, breaks: false });

      /* ---------- 3. Wrap tables ---------- */
      const tmp = document.createElement('div');
      tmp.innerHTML = reply;
      tmp.querySelectorAll('table').forEach(t => {
        const w = document.createElement('div');
        w.className = 'markdown-table-wrapper';
        t.parentNode.insertBefore(w, t);
        w.appendChild(t);
      });
      reply = tmp.innerHTML;

      /* ---------- 4. Restore code blocks ---------- */
      codeBlocks.forEach((html, i) => {
        reply = reply.replace(`@@CB_${i}@@`, html);
      });

      return reply;
    }

});

function recordClientEvent(eventType, data) {
    try {
        const payload = Object.assign({
            event: eventType,
            timestamp: new Date().toISOString(),
            chat_id: typeof chatId !== 'undefined' ? chatId : null
        }, data || {});

        const json = JSON.stringify(payload);
        if (navigator.sendBeacon) {
            const blob = new Blob([json], { type: 'application/json' });
            navigator.sendBeacon('log_client_event.php', blob);
        } else {
            fetch('log_client_event.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                keepalive: true,
                body: json
            }).catch((err) => console.error('recordClientEvent fetch error', err));
        }
    } catch (err) {
        console.error('recordClientEvent failed', err);
    }
}

window.startDocumentProcessingWatch = startDocumentProcessingWatch;
window.notifyDocumentProcessingInProgress = notifyDocumentProcessingInProgress;
window.syncProcessingStateFromServer = syncProcessingStateFromServer;
window.cancelProcessingDocuments = cancelProcessingDocuments;
window.isDocumentProcessingForChat = isDocumentProcessingForChat;
