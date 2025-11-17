'use strict';

var docProcessingBanner;
var docProcessingMessageEl;
var docProcessingFilesEl;
var docProcessingTimerEl;
var docProcessingEstimateEl;
var docProcessingCancelEl;
var docProcessingProgressEl;
var docProcessingProgressFillEl;

var PROCESSING_STORAGE_KEY = 'chat_processing_jobs';

var docProcessingState = {
    active: false,
    docIds: [],
    nameMap: {},
    status: {},
    callbacks: [],
    startTime: null,
    pollInterval: null,
    timerInterval: null,
    pollInFlight: false,
    estimatedTotalSec: null,
    estimateMeta: null,
    dynamicEstimateSec: null
};

function buildDocumentStatusDebugContext() {
    return {
        active: docProcessingState.active,
        docIds: docProcessingState.docIds.slice(),
        statusSnapshot: Object.assign({}, docProcessingState.status || {}),
        timestamp: new Date().toISOString()
    };
}

function logDocumentStatusFailure(context) {
    context = context || {};
    if (!context.timestamp) {
        context.timestamp = new Date().toISOString();
    }
    console.error('Document status request failure', context);
}

window.isDocumentProcessing = false;

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
        status: JSON.parse(JSON.stringify(docProcessingState.status || {})),
        startTime: docProcessingState.startTime,
        estimatedSeconds: docProcessingState.estimatedTotalSec,
        estimateMeta: docProcessingState.estimateMeta,
        dynamicEstimateSec: docProcessingState.dynamicEstimateSec,
        updatedAt: Date.now()
    };

    updateStoredProcessingState(chatId, snapshot);
}

function initializeDocumentProcessingElements() {
    docProcessingBanner = $('#doc-processing-banner');
    docProcessingMessageEl = $('#doc-processing-message');
    docProcessingFilesEl = $('#doc-processing-files');
    docProcessingTimerEl = $('#doc-processing-timer');
    docProcessingEstimateEl = $('#doc-processing-estimate');
    docProcessingCancelEl = $('#doc-processing-cancel');
    docProcessingProgressEl = $('#doc-processing-progress');
    docProcessingProgressFillEl = $('#doc-processing-progress-fill');

    if (docProcessingCancelEl && docProcessingCancelEl.length) {
        docProcessingCancelEl.off('click.doc-cancel').on('click.doc-cancel', handleProcessingCancel);
    }

    updateProcessingControls();
}

function setMessageFormEnabled(enabled) {
    var userMessageInput = $('#userMessage');
    var submitButton = $('#messageForm .submit-button');
    var uploadButton = $('.upload-button');

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
    var filtered = names.filter(function (name) { return !!name; });
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
        .catch(function (err) {
            console.error('Failed to cancel processing:', err);
            alert('Unable to cancel the upload. Please try again.');
        })
        .finally(function () {
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
        .then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        })
        .then(function (data) {
            var canceled = Array.isArray(data.canceled_ids)
                ? data.canceled_ids.map(function (id) { return parseInt(id, 10); }).filter(function (id) { return !Number.isNaN(id); })
                : [];
            var newImages = Array.isArray(data.new_images)
                ? data.new_images.map(function (entry) {
                    if (!entry) {
                        return null;
                    }
                    return {
                        id: parseInt(entry.id, 10),
                        name: entry.name || ''
                    };
                }).filter(function (entry) {
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

            if (typeof fetchAndUpdateChatTitles === 'function') {
                fetchAndUpdateChatTitles($('#search-input').val(), false);
            }
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
        return stored.docIds.some(function (id) {
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

    var idSet = new Set(docIds.map(function (id) { return parseInt(id, 10); }).filter(function (id) { return !Number.isNaN(id); }));
    if (!idSet.size) {
        persistProcessingState();
        updateProcessingControls();
        return;
    }

    docProcessingState.docIds = docProcessingState.docIds.filter(function (id) {
        return !idSet.has(parseInt(id, 10));
    });

    if (!Array.isArray(replaceWithImageIds)) {
        replaceWithImageIds = [];
    }
    replaceWithImageIds.forEach(function (entry) {
        if (!entry) {
            return;
        }
        var imgDocId = parseInt(entry.id, 10);
        var imgName = entry.name || '';
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

    Object.keys(docProcessingState.nameMap || {}).forEach(function (key) {
        if (idSet.has(parseInt(key, 10))) {
            delete docProcessingState.nameMap[key];
        }
    });

    Object.keys(docProcessingState.status || {}).forEach(function (key) {
        if (idSet.has(parseInt(key, 10))) {
            delete docProcessingState.status[key];
        }
    });

    persistProcessingState();
    updateProcessingControls();
}

function removeProcessingDocsFromStorage(targetChatId, docIds) {
    var stored = getStoredProcessingState(targetChatId);
    if (!stored || !Array.isArray(stored.docIds)) {
        return;
    }

    var idSet = new Set(docIds.map(function (id) { return parseInt(id, 10); }).filter(function (id) { return !Number.isNaN(id); }));
    if (!idSet.size) {
        return;
    }

    stored.docIds = stored.docIds.filter(function (id) {
        return !idSet.has(parseInt(id, 10));
    });

    if (stored.status && typeof stored.status === 'object') {
        Object.keys(stored.status).forEach(function (key) {
            if (idSet.has(parseInt(key, 10))) {
                delete stored.status[key];
            }
        });
    }

    if (!stored.docIds.length) {
        updateStoredProcessingState(targetChatId, null);
    } else {
        updateStoredProcessingState(targetChatId, stored);
    }
}

function getPrimaryProcessingStatus() {
    if (!docProcessingState.docIds.length) {
        return null;
    }
    var stageOrder = { uploading: 0, parsing: 1, indexing: 2, ready: 3 };
    var best = null;
    docProcessingState.docIds.forEach(function (docId) {
        var status = docProcessingState.status && docProcessingState.status[docId];
        if (!status) {
            return;
        }
        var stage = (status.stage || '').toLowerCase();
        var statusFlag = (status.status || '').toLowerCase();
        var rank;
        if (statusFlag === 'failed') {
            rank = -1;
        } else if (stageOrder.hasOwnProperty(stage)) {
            rank = stageOrder[stage];
        } else {
            rank = 10;
        }
        if (!best || rank < best.rank) {
            best = { docId: docId, status: status, rank: rank };
        }
    });
    return best;
}

function stageProgressFallback(stage, phase) {
    switch (stage) {
        case 'uploading':
            return 5;
        case 'parsing':
            return phase === 'queued' ? 15 : 45;
        case 'indexing':
            return phase === 'queued' ? 65 : 85;
        case 'ready':
            return 100;
        default:
            return null;
    }
}

function describeProcessingStage(status, docName, defaultSummary) {
    var stage = (status.stage || '').toLowerCase();
    var phase = (status.status || '').toLowerCase();
    var summary = status.message || defaultSummary;
    var message = summary || defaultSummary;

    switch (stage) {
        case 'uploading':
            message = 'Uploading ' + (docName || 'document');
            if (!summary) {
                summary = 'Upload in progress';
            }
            break;
        case 'parsing':
            if (phase === 'queued') {
                message = 'Waiting to start parsing';
            } else if (phase === 'failed') {
                message = 'Parsing failed';
            } else {
                message = 'Parsing ' + (docName || 'document');
            }
            summary = status.message || summary || defaultSummary;
            break;
        case 'indexing':
            if (phase === 'queued') {
                message = 'Waiting for RAG indexing';
            } else if (phase === 'failed') {
                message = 'RAG indexing failed';
            } else {
                message = 'RAG indexing ' + (docName || 'document');
            }
            summary = status.message || summary || defaultSummary;
            break;
        case 'ready':
            message = 'Documents ready';
            summary = '';
            break;
        default:
            message = summary || defaultSummary;
            break;
    }

    var progress = (typeof status.progress === 'number')
        ? status.progress
        : stageProgressFallback(stage, phase);

    return {
        message: message,
        summary: summary,
        progress: progress,
        failed: phase === 'failed'
    };
}

function updateProcessingProgress(percent) {
    if (!docProcessingProgressEl || !docProcessingProgressEl.length || !docProcessingProgressFillEl) {
        return;
    }
    if (!Number.isFinite(percent) || percent < 0) {
        docProcessingProgressEl.hide();
        docProcessingProgressFillEl.css('width', '0%');
        return;
    }
    var clamped = Math.max(0, Math.min(100, Math.round(percent)));
    docProcessingProgressEl.show();
    docProcessingProgressFillEl.css('width', clamped + '%');
}

function getActiveEstimateSeconds() {
    if (Number.isFinite(docProcessingState.dynamicEstimateSec) && docProcessingState.dynamicEstimateSec > 0) {
        return docProcessingState.dynamicEstimateSec;
    }
    if (Number.isFinite(docProcessingState.estimatedTotalSec) && docProcessingState.estimatedTotalSec > 0) {
        return docProcessingState.estimatedTotalSec;
    }
    return null;
}

function updateDynamicEstimate(progressValue) {
    if (!docProcessingState.startTime || !Number.isFinite(progressValue) || progressValue <= 5) {
        docProcessingState.dynamicEstimateSec = null;
        return;
    }
    var elapsedMs = Date.now() - docProcessingState.startTime;
    var elapsedSec = Math.max(1, Math.round(elapsedMs / 1000));
    var cappedProgress = Math.min(99.9, Math.max(progressValue, 1));
    var estimatedTotal = (elapsedSec * 100) / cappedProgress;
    if (estimatedTotal > elapsedSec) {
        docProcessingState.dynamicEstimateSec = estimatedTotal;
    }
}

function renderDocumentProcessingBanner() {
    if (!docProcessingBanner || !docProcessingBanner.length) {
        return;
    }

    if (!docProcessingState.active || !docProcessingState.docIds.length) {
        docProcessingBanner.removeClass('active').hide();
        return;
    }

    docProcessingBanner.addClass('active').show();

    var names = Object.keys(docProcessingState.nameMap || {}).map(function (key) {
        return docProcessingState.nameMap[key];
    });
    var primary = getPrimaryProcessingStatus();
    var defaultSummary = formatDocumentNameSummary(names);
    var summary = defaultSummary;
    var message = 'Processing uploaded documents…';
    var progressValue = null;
    docProcessingBanner.removeClass('doc-processing-error');

    if (primary && primary.status) {
        var docName = docProcessingState.nameMap[primary.docId] || 'document';
        var stageDescriptor = describeProcessingStage(primary.status, docName, defaultSummary);
        message = stageDescriptor.message || message;
        summary = stageDescriptor.summary || summary;
        progressValue = stageDescriptor.progress;
        if (stageDescriptor.failed) {
            docProcessingBanner.addClass('doc-processing-error');
        }
    }

    docProcessingMessageEl.text(message);
    if (summary) {
        docProcessingFilesEl.text(summary);
    } else {
        docProcessingFilesEl.text(docProcessingState.docIds.length + ' document(s)');
    }
    updateProcessingProgress(progressValue);
    if (Number.isFinite(progressValue)) {
        updateDynamicEstimate(progressValue);
    }

    var estimateSeconds = getActiveEstimateSeconds();
    var estimateText = '';
    if (Number.isFinite(estimateSeconds) && estimateSeconds > 0) {
        estimateText = 'Est. ' + formatDurationHuman(estimateSeconds);
    }
    docProcessingEstimateEl.text(estimateText);

    updateDocumentProcessingTimer();
}

function updateDocumentProcessingTimer() {
    if (!docProcessingState.startTime || !docProcessingTimerEl || !docProcessingTimerEl.length) {
        return;
    }

    var elapsed = Math.floor((Date.now() - docProcessingState.startTime) / 1000);
    var text = formatElapsedClock(elapsed);
    var estimateSeconds = getActiveEstimateSeconds();
    if (Number.isFinite(estimateSeconds) && estimateSeconds > 0) {
        text += ' / ~' + formatDurationHuman(estimateSeconds);
    }
    docProcessingTimerEl.text(text);
}

function pollDocumentProcessingStatus() {
    if (!docProcessingState.active || docProcessingState.docIds.length === 0) {
        finishDocumentProcessing();
        return;
    }

    if (docProcessingState.pollInFlight) {
        return;
    }
    docProcessingState.pollInFlight = true;

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
        .then(function (response) {
            if (!response.ok) {
                var ctx = buildDocumentStatusDebugContext();
                ctx.httpStatus = response.status;
                ctx.httpStatusText = response.statusText;
                ctx.url = response.url;
                return response.text().then(function (text) {
                    ctx.responseSnippet = text ? text.slice(0, 400) : '';
                    logDocumentStatusFailure(ctx);
                    throw new Error('HTTP ' + response.status);
                });
            }
            return response.json();
        })
        .then(function (data) {
            if (!data || !Array.isArray(data.documents)) {
                return;
            }

            var completedIds = [];
            var statusUpdated = false;
            var failedEntries = [];
            data.documents.forEach(function (entry) {
                var docId = parseInt(entry.document_id, 10);
                if (Number.isNaN(docId)) {
                    return;
                }
                if (entry.processing) {
                    docProcessingState.status[docId] = entry.processing;
                    statusUpdated = true;
                }
                if (entry.ready) {
                    completedIds.push(docId);
                } else if (entry.processing && entry.processing.status === 'failed') {
                    failedEntries.push({ id: docId, status: entry.processing });
                }
            });

            if (completedIds.length) {
                docProcessingState.docIds = docProcessingState.docIds.filter(function (id) {
                    if (completedIds.indexOf(id) !== -1) {
                        delete docProcessingState.nameMap[id];
                        return false;
                    }
                    return true;
                });
                persistProcessingState();
            }

            if (failedEntries.length) {
                var failedIds = failedEntries.map(function (entry) { return entry.id; });
                var failureSummaries = failedEntries.map(function (entry) {
                    return {
                        id: entry.id,
                        status: entry.status || {},
                        name: docProcessingState.nameMap[entry.id] || 'Document'
                    };
                });
                removeProcessingDocs(failedIds, []);
                failureSummaries.forEach(function (entry) {
                    var message = entry.status.message || 'Document failed to process.';
                    var full = entry.name + ': ' + message;
                    if (typeof window.showToastMessage === 'function') {
                        window.showToastMessage(full);
                    } else {
                        console.warn(full);
                    }
                });
            }

            if (statusUpdated) {
                persistProcessingState();
            }

            if (docProcessingState.docIds.length === 0 || data.all_ready) {
                finishDocumentProcessing();
            } else {
                renderDocumentProcessingBanner();
            }
        })
        .catch(function (error) {
            if (error && error.name === 'AbortError') {
                return;
            }
            var transientMessages = [
                'Load failed',
                'NetworkError when attempting to fetch resource.',
                'Failed to fetch',
                'The network connection was lost.'
            ];
            if (error && transientMessages.indexOf(error.message) !== -1) {
                console.warn('Document status poll interrupted:', error.message);
            } else {
                var ctx = buildDocumentStatusDebugContext();
                ctx.errorMessage = error && error.message ? error.message : String(error);
                logDocumentStatusFailure(ctx);
            }
        })
        .finally(function () {
            docProcessingState.pollInFlight = false;
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
        setTimeout(function () {
            docProcessingBanner.removeClass('active').fadeOut(200);
        }, 800);
    }

    setMessageFormEnabled(true);
    window.isDocumentProcessing = false;

    var callbacks = docProcessingState.callbacks.slice();

    docProcessingState.active = false;
    docProcessingState.docIds = [];
    docProcessingState.nameMap = {};
    docProcessingState.status = {};
    docProcessingState.callbacks = [];
    docProcessingState.startTime = null;
    docProcessingState.pollInFlight = false;
    docProcessingState.estimatedTotalSec = null;
    docProcessingState.estimateMeta = null;
    docProcessingState.dynamicEstimateSec = null;

    persistProcessingState();
    updateProcessingControls();

    if (typeof fetchAndUpdateChatTitles === 'function') {
        var currentSearch = $('#search-input').val();
        fetchAndUpdateChatTitles(currentSearch, false);
    }

    callbacks.forEach(function (callback) {
        if (typeof callback === 'function') {
            try {
                callback();
            } catch (err) {
                console.error('Deferred submission failed:', err);
            }
        }
    });
}

function startDocumentProcessingWatch(documents, options) {
    options = options || {};
    var initialStatuses = (options.statuses && typeof options.statuses === 'object') ? options.statuses : null;

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
        docProcessingState.dynamicEstimateSec = null;
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

    if (Number.isFinite(options.dynamicEstimateSec) && options.dynamicEstimateSec > 0) {
        docProcessingState.dynamicEstimateSec = options.dynamicEstimateSec;
    }

    documents.forEach(function (doc) {
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
        var seededStatus = null;
        if (initialStatuses && initialStatuses[docId]) {
            seededStatus = JSON.parse(JSON.stringify(initialStatuses[docId]));
        } else if (doc.processing_status) {
            seededStatus = JSON.parse(JSON.stringify(doc.processing_status));
        }
        if (seededStatus) {
            docProcessingState.status[docId] = seededStatus;
        }
    });

    if (options.docNames && Array.isArray(options.docNames)) {
        options.docNames.forEach(function (name, index) {
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
    setTimeout(function () {
        docProcessingBanner.removeClass('pulse');
    }, 1200);
}

function syncProcessingStateFromServer(chatData) {
    if (!chatId) {
        return;
    }

    if (docProcessingState.active) {
        persistProcessingState();
        return;
    }

    var serverChat = null;
    if (chatData) {
        if (Array.isArray(chatData)) {
            serverChat = chatData.find(function (item) { return item && item.id === chatId; });
        } else if (chatData[chatId]) {
            serverChat = chatData[chatId];
        }
    }

    var storedState = getStoredProcessingState(chatId);
    if (!serverChat && !storedState) {
        return;
    }

    if (storedState && storedState.docIds && storedState.docIds.length) {
        var resumeDocs = storedState.docIds.map(function (id) {
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
            resumeDocs = resumeDocs.filter(function (doc) {
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
            docNames: resumeDocs.map(function (doc) { return doc.name; }),
            startTime: storedState.startTime,
            estimatedSeconds: storedState.estimatedSeconds || null,
            estimateMeta: storedState.estimateMeta || null,
            dynamicEstimateSec: storedState.dynamicEstimateSec || null,
            statuses: storedState.status || null
        });
    }
}

window.startDocumentProcessingWatch = startDocumentProcessingWatch;
window.notifyDocumentProcessingInProgress = notifyDocumentProcessingInProgress;
window.syncProcessingStateFromServer = syncProcessingStateFromServer;
window.cancelProcessingDocuments = cancelProcessingDocuments;
window.isDocumentProcessingForChat = isDocumentProcessingForChat;
