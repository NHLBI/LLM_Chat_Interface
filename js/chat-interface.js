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
var DEFAULT_TTS_VOICE = 'af_heart';
var SPEAK_ICON = '<img src="images/speaker.svg" alt="" class="speak-icon">';
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

var ttsEnabled = typeof window !== 'undefined'
    && typeof window.fetch === 'function'
    && typeof window.Audio !== 'undefined';
var activeSpeakButton = null;
var activeTtsControllers = [];
var audioContext = null;
var speakerGainNode = null;
var activeBufferSource = null;
var audioQueue = [];
var isAudioContextPlaying = false;
var activeSpeakMetadata = null;
var pendingSpeakFinish = false;
var activeSpeakSessionId = null;
var speakSessionCounter = 0;

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

    const deploymentMeta = (deployments && deployment && deployments[deployment]) ? deployments[deployment] : null;
    const avatarSrc = deploymentMeta && deploymentMeta.image ? `images/${deploymentMeta.image}` : 'images/openai_logo.svg';
    const avatarAlt = deploymentMeta && deploymentMeta.image_alt ? deploymentMeta.image_alt : 'Assistant avatar';

    assistantMessageElement.prepend(`
        <img src="${avatarSrc}"
             alt="${avatarAlt}"
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

setTimeout(function () {
    if (typeof loadMessages === 'function') {
        loadMessages();
    }
}, 150);

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

function stopActiveAudio() {
        activeSpeakSessionId = null;
        activeTtsControllers.forEach(function (controller) {
            try {
                controller.abort();
            } catch (e) {
                console.warn('Abort controller failed', e);
            }
        });
        activeTtsControllers = [];

        pendingSpeakFinish = false;
        activeSpeakSessionId = null;

        if (activeBufferSource) {
            try {
                activeBufferSource.stop();
            } catch (e) {
                console.warn('Unable to stop buffer source', e);
            }
        }
        activeBufferSource = null;

        audioQueue = [];
        isAudioContextPlaying = false;

        if (audioContext) {
            try {
                audioContext.close();
            } catch (e) {
                console.warn('Unable to close audio context', e);
            }
        }
        audioContext = null;
        speakerGainNode = null;
        activeSpeakMetadata = null;
}

function resetSpeakButton(button) {
    if (!button) {
        return;
    }
    button.attr('aria-label', 'Play audio for this reply');
    setSpeakButtonLabel(button, 'Play audio');
    button.removeClass('speak-playing');
    var status = button.data('speakStatus');
    if (status) {
        status.text('');
    }
}

function setSpeakButtonPlaying(button) {
        button.attr('aria-label', 'Stop audio playback');
        setSpeakButtonLabel(button, 'Stop Audio');
    button.addClass('speak-playing');
}

function setSpeakButtonLabel(button, text) {
    if (!button) {
        return;
    }
    button.html('<span>' + text + '</span>' + SPEAK_ICON);
}

function resolveDocumentIconPath(docType, name) {
    var lower = (docType || '').toLowerCase();
    if (lower.indexOf('pdf') !== -1) {
        return DOCUMENT_ICON_MAP.pdf;
    }
    if (lower.indexOf('word') !== -1 || lower.indexOf('msword') !== -1 || lower.indexOf('doc') !== -1) {
        return DOCUMENT_ICON_MAP.docx;
    }
    if (lower.indexOf('presentation') !== -1 || lower.indexOf('powerpoint') !== -1 || lower.indexOf('ppt') !== -1) {
        return DOCUMENT_ICON_MAP.pptx;
    }
    if (lower.indexOf('sheet') !== -1 || lower.indexOf('excel') !== -1 || lower.indexOf('spreadsheet') !== -1 || lower.indexOf('xls') !== -1) {
        return DOCUMENT_ICON_MAP.xlsx;
    }
    if (lower.indexOf('csv') !== -1) {
        return DOCUMENT_ICON_MAP.csv;
    }
    if (lower.indexOf('json') !== -1) {
        return DOCUMENT_ICON_MAP.json;
    }

    var base = (name || '').toLowerCase();
    var match = base.match(/\.([a-z0-9]+)$/);
    if (match) {
        var ext = match[1];
        if (DOCUMENT_ICON_MAP[ext]) {
            return DOCUMENT_ICON_MAP[ext];
        }
    }
    return 'images/paperclip.svg';
}

function openDocumentExcerpt(docId, trigger) {
    if (!docId) {
        return;
    }
    var $trigger = $(trigger);
    $trigger.addClass('document-attachment-loading');

    $.ajax({
        url: 'document_excerpt.php',
        method: 'GET',
        dataType: 'json',
        data: { document_id: docId },
        success: function(response) {
            $trigger.removeClass('document-attachment-loading');

            if (!response || response.ok !== true || !response.document) {
                alert('Unable to load the document preview at this time.');
                return;
            }

            if (typeof window.showDocumentExcerptModal === 'function') {
                window._documentExcerptReturnFocus = $trigger.get(0);
                window.showDocumentExcerptModal(response.document);
            } else {
                console.warn('showDocumentExcerptModal is not available on the window object.');
            }
        },
        error: function(xhr) {
            $trigger.removeClass('document-attachment-loading');
            var message = 'Unable to load the document preview.';
            if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                message = xhr.responseJSON.message;
            }
            alert(message);
        }
    });
}

function renderMessageAttachments(messageElement, documents) {
    if (!messageElement || !messageElement.length) {
        return;
    }

    var normalized = [];
    if (Array.isArray(documents)) {
        documents.forEach(function(doc) {
            if (doc && typeof doc === 'object') {
                normalized.push(doc);
            }
        });
    } else if (typeof documents === 'object' && documents !== null) {
        Object.keys(documents).forEach(function(key) {
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

    normalized.forEach(function(doc) {
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
            chip.on('click keypress', function(event) {
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

    function chunkTextForTts(text) {
        var tiers = [
            { max: 100, min: 50 },  // first chunk ~1/4 of previous size
            { max: 175, min: 60 },  // first chunk ~1/4 of previous size
            { max: 250, min: 120 }, // second chunk ~half size
            { max: 375, min: 180 }, // third chunk between half and full
            { max: 500, min: 200 }  // final/default chunk size
        ];

        var remaining = text.trim();
        var chunks = [];
        var tierIndex = 0;

        while (remaining.length > 0) {
            var activeTier = tiers[Math.min(tierIndex, tiers.length - 1)];
            var maxLen = activeTier.max;
            var minLen = activeTier.min;

            if (remaining.length <= maxLen) {
                chunks.push(remaining);
                break;
            }

            var slice = remaining.slice(0, maxLen);
            var breakPoint = Math.max(
                slice.lastIndexOf('. '),
                slice.lastIndexOf('! '),
                slice.lastIndexOf('? '),
                slice.lastIndexOf('\n'),
                slice.lastIndexOf(' ')
            );

            if (breakPoint < minLen) {
                breakPoint = slice.lastIndexOf(' ');
                if (breakPoint < minLen) {
                    breakPoint = maxLen;
                }
            }

            if (breakPoint <= 0) {
                breakPoint = maxLen;
            }

            var rawChunk = slice.slice(0, breakPoint);
            var chunk = rawChunk.trim();
            if (!chunk) {
                rawChunk = slice;
                chunk = rawChunk.trim();
            }
            if (chunk) {
                chunks.push(chunk);
            }
            var consumed = rawChunk.length;
            if (consumed <= 0) {
                consumed = Math.min(maxLen, remaining.length);
            }
            remaining = remaining.slice(consumed).trim();
            if (tierIndex < tiers.length - 1) {
                tierIndex += 1;
            }
        }

        if (!chunks.length) {
            chunks.push(text);
        }

        return chunks;
    }

    function fetchAudioChunk(text) {
        var controller = new AbortController();
        activeTtsControllers.push(controller);

        return fetch('tts_proxy.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                text: text,
                voice: DEFAULT_TTS_VOICE
            }),
            signal: controller.signal
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('TTS request failed with status ' + response.status);
            }
            var contentType = response.headers.get('Content-Type') || '';
            if (contentType.indexOf('audio') === -1) {
                throw new Error('Unexpected content-type ' + contentType);
            }
            return response.arrayBuffer();
        }).finally(function () {
            var index = activeTtsControllers.indexOf(controller);
            if (index !== -1) {
                activeTtsControllers.splice(index, 1);
            }
        });
    }

    function ensureAudioContext() {
        if (!window.AudioContext && !window.webkitAudioContext) {
            throw new Error('Web Audio API not supported in this browser');
        }
        if (!audioContext) {
            var Ctor = window.AudioContext || window.webkitAudioContext;
            audioContext = new Ctor();
        }
        if (!speakerGainNode && audioContext) {
            speakerGainNode = audioContext.createGain();
            speakerGainNode.gain.setValueAtTime(1, audioContext.currentTime);
            speakerGainNode.connect(audioContext.destination);
        }
        if (audioContext.state === 'suspended') {
            audioContext.resume().catch(function (err) {
                console.warn('Unable to resume audio context', err);
            });
        }
        return audioContext;
}

    function queueAudioBuffer(buffer) {
        audioQueue.push(buffer);
        if (!isAudioContextPlaying) {
            playNextBuffer();
        }
    }

    function playNextBuffer() {
        if (!audioQueue.length || !audioContext) {
            isAudioContextPlaying = false;
            checkPlaybackCompletion();
            return;
        }

        isAudioContextPlaying = true;
        var buffer = audioQueue.shift();
        var source = audioContext.createBufferSource();
        activeBufferSource = source;
        source.buffer = buffer;

        var destination = speakerGainNode ? speakerGainNode : audioContext.destination;
        try {
            source.connect(destination);
        } catch (connectErr) {
            console.error('Audio buffer connect failed:', connectErr);
            activeBufferSource = null;
            isAudioContextPlaying = false;
            checkPlaybackCompletion();
            return;
        }

        source.onended = function () {
            activeBufferSource = null;
            playNextBuffer();
        };

        try {
            source.start(0);
        } catch (e) {
            console.error('Audio buffer start failed:', e);
            activeBufferSource = null;
            isAudioContextPlaying = false;
            playNextBuffer();
        }
    }

    function checkPlaybackCompletion() {
        if (pendingSpeakFinish && audioQueue.length === 0 && !isAudioContextPlaying) {
            finalizeSpeakSession();
        }
    }

    function finalizeSpeakSession() {
        var meta = activeSpeakMetadata;
        activeSpeakMetadata = null;
        pendingSpeakFinish = false;

        stopActiveAudio();

        if (meta && meta.status) {
            meta.status.text('');
        }
        if (meta && meta.button) {
            resetSpeakButton(meta.button);
        }
        activeSpeakButton = null;
    }

    function addSpeakButton(messageElement, rawMessageContent) {
        if (!ttsEnabled) {
            return;
        }

        var plain = (rawMessageContent || '').replace(/\s+/g, ' ').trim();
        if (!plain) {
            return;
        }

        var speakButton = $('<button type="button" class="copy-chat-button speak-chat-button" title="Play audio for this reply" aria-label="Play audio for this reply"><svg xmlns="http://www.w3.org/2000/svg" version="1.0" width="202.000000pt" height="174.000000pt" viewBox="0 0 202.000000 174.000000" preserveAspectRatio="xMidYMid meet"><metadata>Created by potrace 1.10, written by Peter Selinger 2001-2011</metadata><g transform="translate(0.000000,174.000000) scale(0.100000,-0.100000)" fill="#a5803b" stroke="none"><path d="M875 1620 c-16 -11 -110 -103 -209 -205 l-178 -185 -152 0 c-167 0 -187 -6 -223 -59 -23 -33 -23 -40 -23 -303 0 -292 2 -305 56 -347 25 -19 40 -21 184 -21 l156 0 184 -191 c101 -104 196 -197 211 -205 59 -30 113 -12 147 49 16 29 17 86 20 676 2 442 -1 661 -8 698 -21 98 -94 140 -165 93z m45 -757 l0 -628 -198 198 -197 197 -158 0 -157 0 0 235 0 235 158 0 157 0 195 195 c107 107 196 195 197 195 2 0 3 -282 3 -627z"/><path d="M1564 1552 c-19 -12 -35 -52 -28 -65 4 -6 33 -41 65 -77 206 -236 267 -526 168 -808 -38 -108 -84 -185 -167 -281 -68 -77 -80 -113 -46 -138 47 -34 96 -2 194 129 73 97 131 215 161 328 33 121 33 329 0 450 -30 113 -88 231 -161 328 -92 123 -146 161 -186 134z"/><path d="M1384 1382 c-6 -4 -16 -16 -22 -28 -13 -24 -6 -37 60 -119 64 -80 95 -140 118 -231 25 -98 25 -180 0 -278 -23 -91 -54 -151 -118 -231 -26 -32 -52 -66 -58 -77 -25 -43 17 -87 70 -74 55 14 171 182 218 316 24 68 35 250 19 333 -22 118 -89 249 -176 343 -50 53 -81 66 -111 46z"/><path d="M1198 1208 c-34 -27 -30 -53 17 -113 66 -86 88 -151 83 -250 -5 -89 -24 -137 -89 -219 -44 -54 -46 -62 -23 -94 27 -38 70 -30 120 25 127 138 157 339 74 506 -59 121 -135 181 -182 145z"/></g></svg></button>');
        setSpeakButtonLabel(speakButton, 'Play Audio');
        var statusSpan = $('<span class="speak-status" aria-live="polite"></span>');
        speakButton.data('speakStatus', statusSpan);

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

        speakButton.css('margin-left', '10px');

        speakButton.on('click', function (event) {
            event.preventDefault();
            if (!ttsEnabled) {
                return;
            }

            var sameButtonActive = activeSpeakButton && activeSpeakButton[0] === speakButton[0];

            stopActiveAudio();
            if (activeSpeakButton) {
                resetSpeakButton(activeSpeakButton);
                activeSpeakButton = null;
            }

            if (sameButtonActive) {
                return;
            }

            var status = speakButton.data('speakStatus');
            if (status) {
                status.text('Preparing audio…');
            }

            activeSpeakButton = speakButton;
            var sessionId = ++speakSessionCounter;
            activeSpeakSessionId = sessionId;
            setSpeakButtonPlaying(speakButton);
            activeTtsControllers = [];
            activeSpeakMetadata = {
                button: speakButton,
                status: status
            };
            pendingSpeakFinish = false;

            var sanitized = plain.replace(/[*_`]/g, '');
            var chunks = chunkTextForTts(sanitized);
            var chunkFetches = [];

            function ensureFetch(index) {
                if (index >= chunks.length) {
                    return null;
                }
                if (!chunkFetches[index]) {
                    chunkFetches[index] = fetchAudioChunk(chunks[index]);
                }
                return chunkFetches[index];
            }

            ensureFetch(0);
            if (chunks.length > 1) {
                ensureFetch(1);
            }

            var currentIndex = 0;

            function playChunk(index) {
                var fetchPromise = ensureFetch(index);
                if (!fetchPromise) {
                    finishPlayback();
                    return;
                }
                fetchPromise.then(function (blob) {
                    if (activeSpeakSessionId !== sessionId) {
                        return;
                    }

                    if (index + 1 < chunks.length) {
                        ensureFetch(index + 1);
                    }

                    if (status) {
                        status.text('Playing audio (' + (index + 1) + '/' + chunks.length + ')…');
                    }

                    if (activeSpeakSessionId !== sessionId) {
                        return;
                    }

                    ensureAudioContext();
                    audioContext.decodeAudioData(blob, function (decoded) {
                        if (activeSpeakSessionId !== sessionId) {
                            return;
                        }
                        queueAudioBuffer(decoded);
                    }, function (decodeErr) {
                        if (activeSpeakSessionId !== sessionId) {
                            return;
                        }
                        console.error('Audio decode failed:', decodeErr);
                        if (status) {
                            status.text('Audio playback failed.');
                        }
                        stopActiveAudio();
                        resetSpeakButton(speakButton);
                        activeSpeakButton = null;
                    });

                    currentIndex = index + 1;
                    if (currentIndex < chunks.length) {
                        ensureFetch(currentIndex + 1);
                        playChunk(currentIndex);
                    } else {
                        finishPlayback();
                    }
                }).catch(function (err) {
                    if (activeSpeakSessionId !== sessionId) {
                        return;
                    }
                    console.error('TTS fetch failed:', err);
                    if (status) {
                        status.text('Audio unavailable.');
                    }
                    stopActiveAudio();
                    resetSpeakButton(speakButton);
                    activeSpeakButton = null;
                });
            }

            function finishPlayback() {
                pendingSpeakFinish = true;
                checkPlaybackCompletion();
            }

            playChunk(0);
        });

        controls.append(speakButton, statusSpan);
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
        // Stop any ongoing audio playback when switching chats.
        stopActiveAudio();
        if (activeSpeakButton) {
            resetSpeakButton(activeSpeakButton);
            activeSpeakButton = null;
        }

        chatContainer.empty();
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
                console.log("THIS IS JUST BEFORE THE CRAZY MESSAGE CODUMENTD");
                console.log(message.document);
            if (message.document) {
                console.log("THIS IS THE CRAZY MESSAGE CODUMENTD");
                console.log(message.document);
                renderMessageAttachments(userMessageElement, message.document);
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
