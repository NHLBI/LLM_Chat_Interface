//------------------------------------------
// Helper:   3247323 → 3.2 M, 64353 → 64 K
//------------------------------------------
if (typeof window.extractDocumentMetadataFromElement !== 'function') {
    window.extractDocumentMetadataFromElement = function (element) {
        if (!element) {
            return null;
        }
        var $el;
        if (window.jQuery && element instanceof window.jQuery) {
            $el = element;
        } else {
            $el = $(element);
        }
        if (!$el || !$el.length) {
            return null;
        }
        var idAttr = $el.attr('data-document-id');
        var docId = parseInt(idAttr || '0', 10);
        if (!Number.isFinite(docId) || docId <= 0) {
            return null;
        }

        var enabledAttr = $el.attr('data-document-enabled');
        var enabled = !(enabledAttr === '0' || enabledAttr === 'false');

        var readyAttr = $el.attr('data-document-ready');
        var ready = readyAttr === '1' || readyAttr === 'true';

        var tokenAttr = $el.attr('data-document-tokens');
        var tokens = parseInt(tokenAttr || '0', 10);
        if (!Number.isFinite(tokens)) {
            tokens = 0;
        }

        var name = $el.attr('data-document-name') || $el.find('.document-title').text().trim();

        var meta = {
            document_id: docId,
            document_name: name,
            document_type: $el.attr('data-document-type') || '',
            document_source: $el.attr('data-document-source') || '',
            source: $el.attr('data-document-source') || '',
            document_ready: ready ? 1 : 0,
            document_token_length: tokens,
            document_deleted: 0,
            enabled: enabled,
            was_enabled: enabled
        };
        return meta;
    };
}

function prettyTokens(n) {
    // If Intl.NumberFormat with compact notation is supported
    if (Intl && Intl.NumberFormat) {
        return new Intl.NumberFormat('en', {
            notation: 'compact',
            maximumFractionDigits: 1   // 3 244 000 → 3.2 M
        }).format(n);
    }

    // Fallback manual formatter
    if      (n >= 1e9) return (n / 1e9).toFixed(1).replace(/\.0$/, '') + 'B';
    else if (n >= 1e6) return (n / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
    else if (n >= 1e3) return (n / 1e3).toFixed(0)                    + 'K';
    return n.toString();
}

function fetchAndUpdateChatTitles(searchString, clearSearch) {

    // Check if searchingIndicator exists
    if (!searchingIndicator) {
        console.error('Error: searchingIndicator element not found in the DOM.');
        return;
    }

    if (search_term !== '') {
        console.log(search_term);
        const openSearchButton = document.getElementById('open-search');
        const cancelSearchButton = document.getElementById('cancel-search');
        const searchInput = document.getElementById('search-input');

        // Handle click on the search icon button
        searchInput.classList.add('open');
        cancelSearchButton.style.display = 'inline-block';
        openSearchButton.style.display = 'none';
        searchInput.focus();
    }

    // Show the searching indicator
    searchingIndicator.style.display = 'block';
    chatTitlesContainer.style.opacity = '0.5'; // Dim the container while loading

    function fetchDocumentExcerpt($trigger) {
        if (!$trigger || $trigger.length === 0) {
            return;
        }
        const docId = $trigger.data('document-id');
        if (!docId) {
            return;
        }

        $trigger.addClass('document-title-loading');

        $.ajax({
            url: 'document_excerpt.php',
            method: 'GET',
            dataType: 'json',
            data: { document_id: docId },
            success: function(response) {
                $trigger.removeClass('document-title-loading');

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
                $trigger.removeClass('document-title-loading');
                let message = 'Unable to load the document preview.';
                if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                    message = xhr.responseJSON.message;
                }
                alert(message);
            }
            });
    }

    function fetchImagePreview($trigger, docId, chatId, fallbackMeta) {
        if (!$trigger || $trigger.length === 0 || !docId) {
            return;
        }

        $trigger.addClass('document-title-loading');
        $.ajax({
            url: 'document_excerpt.php',
            method: 'GET',
            dataType: 'json',
            data: { document_id: docId },
            success: function (response) {
                if (!response || response.ok !== true || !response.document) {
                    alert('Unable to display an image preview for this document.');
                    return;
                }

                const doc = response.document || {};
                const imageSrc = doc.image_src || doc.document_content || doc.content || '';
                if (!imageSrc) {
                    alert('Unable to display an image preview for this document.');
                    return;
                }

                var payload = Object.assign({}, fallbackMeta || {}, {
                    document_id: docId,
                    document_name: doc.name || (fallbackMeta && fallbackMeta.document_name) || '',
                    document_type: doc.type || (fallbackMeta && fallbackMeta.document_type) || '',
                    document_source: doc.source || (fallbackMeta && fallbackMeta.document_source) || 'image',
                    document_content: imageSrc,
                    document_text: imageSrc,
                    src: imageSrc
                });

                if (window.chatDocumentsByChatId) {
                    window.chatDocumentsByChatId[chatId] = window.chatDocumentsByChatId[chatId] || {};
                    window.chatDocumentsByChatId[chatId][docId] = Object.assign(
                        window.chatDocumentsByChatId[chatId][docId] || {},
                        payload
                    );
                }

                if (typeof openImagePreview === 'function') {
                    openImagePreview(payload, $trigger);
                } else if (typeof window.openImagePreview === 'function') {
                    window.openImagePreview(payload, $trigger);
                } else {
                    alert('Image preview is not available at this time.');
                }
            },
            error: function () {
                alert('Unable to display an image preview for this document.');
            },
            complete: function () {
                $trigger.removeClass('document-title-loading');
            }
        });
    }

    $.ajax({
        url: 'get_chat_titles.php',
        type: 'GET',
        dataType: 'json',
        data: { search: searchString, clearSearch: clearSearch }, // Pass the search string here
        success: function(response) {
            // Hide the searching indicator
            searchingIndicator.style.display = 'none';
            chatTitlesContainer.style.opacity = '1'; // Restore opacity
            //console.log(response);

            // Clear the current chat titles
            $('.chat-titles-container').empty();

            const chatData = response || {};

            if (searchString.trim() !== '') {
                // Handle the case where no results are found
                if (Object.keys(chatData).length === 0) {
                    $('.chat-titles-container').append(`
                        <div class="no-results">
                            <p>No results found for "${searchString}".</p>
                        </div>
                    `);
                } else {
                    $('.chat-titles-container').append(`
                        <div class="no-results">
                            <p>Chats including: "${searchString}"</p>
                        </div>
                    `);
                }
            }

            // Remove any existing popup to prevent duplicate event listeners
            $('#popup').remove();

            // Create new popup with initial hidden state and opacity
            $('body').append(`
                <div id="popup" class="popup" style="display: none; opacity: 0; transition: opacity 0.3s ease-out;">
                    <div class="popup-toolbar">



                        <button class="popup-icon copy-title-button" title="Copy Title">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16">
                                <path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/>
                            </svg>
                        </button>
                        <button class="popup-icon edit-icon" title="Edit this chat">
                            <svg version="1.1" id="Layer_1" xmlns="http://www.w3.org/2000/svg" 
                                xmlns:xlink="http://www.w3.org/1999/xlink" x="0px" y="0px" width="17px" height="17px" 
                                viewBox="0 0 32 32" enable-background="new 0 0 32 32" xml:space="preserve">
                                <path fill="#FFFFFF" d="M0,31.479c0,0.276,0.224,0.5,0.5,0.5h31.111c0.067,0,0.132-0.013,0.193-0.039
                                    c0.061-0.026,0.116-0.063,0.162-0.109c0.001-0.001,0.002-0.001,0.003-0.002c0.003-0.003,0.003-0.009,0.007-0.012
                                    c0.051-0.055,0.084-0.122,0.107-0.195c0.007-0.023,0.01-0.045,0.014-0.069c0.004-0.025,0.015-0.047,0.015-0.073
                                    c0-0.04-0.014-0.075-0.023-0.112c-0.003-0.014,0.003-0.028-0.002-0.042l-3.16-9.715c-0.024-0.075-0.066-0.144-0.122-0.199
                                    L11.688,4.294c-0.018-0.028-0.031-0.058-0.055-0.083L7.894,0.472c-0.607-0.607-1.595-0.607-2.203,0L0.456,5.707
                                    C0.162,6.001,0,6.392,0,6.808s0.162,0.808,0.456,1.102l3.656,3.656c0.018,0.027,0.03,0.058,0.054,0.082l17.09,17.205
                                    c0.059,0.06,0.131,0.103,0.212,0.127l6.713,2H0.5C0.224,30.979,0,31.203,0,31.479z M6.362,10.161l15.687,15.486l-0.577,2.002
                                    L5.227,11.296L6.362,10.161z M22.816,25L7.068,9.455l2.437-2.437l15.607,15.648V25H22.816z M25.735,21.875L10.212,6.311
                                    l1.039-1.039l16.211,16.211L25.735,21.875z M22.988,26h2.624c0.276,0,0.5-0.224,0.5-0.5v-2.685l2.007-0.456l2.723,8.37L22.354,28.2
                                    L22.988,26z M1,6.808C1,6.659,1.058,6.52,1.163,6.414l5.235-5.235c0.217-0.217,0.57-0.218,0.789,0l3.372,3.372l-6.023,6.023
                                    L1.164,7.202C1.058,7.097,1,6.957,1,6.808z" stroke="#f2f2f2" stroke-width="1"/>
                            </svg>
                        </button>
                        <button class="popup-icon delete-icon" title="Delete this chat">
                            ${TRASH_SVG}
                        </button>
                    </div>
                </div>
            `);

            popup = $('#popup');
            let hideTimer = null;
            let autoCloseTimer = null;

            // Function to handle showing popup
            function showPopup(e, chatId, chatTitle) {
                if (hideTimer) {
                    clearTimeout(hideTimer);
                }
                // Clear any existing auto-close timer
                if (autoCloseTimer) {
                    clearTimeout(autoCloseTimer);
                }
                // Close any existing edit forms when popup is triggered
                $('.chat-item').each(function() {
                    const $existingEditInput = $(this).find('.edit-field');
                    if ($existingEditInput.length > 0) {
                        const existingChatId = $existingEditInput.attr('id').replace('edit-input-', '');
                        revertEditForm(existingChatId);
                    }
                });
                // Set new auto-close timer
                autoCloseTimer = setTimeout(() => {
                    // Fade out the popup
                    popup.css('opacity', '0');
                    // Hide the popup after fade-out completes
                    setTimeout(() => {
                        popup.hide();
                    }, 150);
                }, 2000);
                popup.data('currentChatId', chatId);
                popup.data('currentChatTitle', chatTitle);


                // Determine the current chat based on the URL
                const currentChatId = window.location.pathname.split('/')[2];

                // Conditionally show the paperclip icon only if this chat is the current chat
                if (chatId === currentChatId) {
                    $('#popup .paperclip-icon').show();
                } else {
                    $('#popup .paperclip-icon').hide();
                }



                const ellipsisRect = e.target.getBoundingClientRect();
                popup.css({
                    top: ellipsisRect.top + 'px',
                    display: 'block',
                    opacity: '1'
                });
            }

            // Add popup event listeners
            popup.on('mouseenter', () => {
                if (hideTimer) {
                    clearTimeout(hideTimer);
                }
                // Clear the auto-close timer when user hovers
                if (autoCloseTimer) {
                    clearTimeout(autoCloseTimer);
                }
                // Ensure popup is fully visible
                popup.css('opacity', '1');
            }).on('mouseleave', startHideTimer);

            // Add click handlers for popup buttons
            popup.on('click', '.edit-icon', function() {
                const chatId = popup.data('currentChatId');
                popup.hide();
                editChat(chatId, showPopup);
            });

            popup.on('click', '.delete-icon', function() {
                const chatId = popup.data('currentChatId');
                const chatTitle = popup.data('currentChatTitle');
                popup.hide();
                deleteChat(chatId, chatTitle);
            });

            popup.on('click', '.copy-title-button', function() {
                const chatId = popup.data('currentChatId');
                const chatTitle = popup.data('currentChatTitle');
                copyTitleToClipboard(chatId, chatTitle);
            });

            // In your AJAX success callback, when iterating over chats:
            Object.values(chatData).forEach(function(chat, index) {
    //console.log("this is the full chat item: ");
    //console.log(chat);
                const isCurrentChat = chat.id === window.location.pathname.split('/')[2];
                const chatItemClass = isCurrentChat ? 'chat-item current-chat initial-current-chat' : 'chat-item';

                const chatItem = $('<div>', {
                    class: chatItemClass,
                    id: `chat-${chat.id}`
                });

                const chatLink = $('<a>', {
                    class: 'chat-link chat-title',
                    title: chat.title,
                    href: `/${application_path}/${chat.id}`,
                    text: chat.title,
                    'data-chat-id': chat.id,
                    'data-chat-title': chat.title
                });

                // Create ellipsis link for popup trigger
                const ellipsisLink = $('<a>', {
                    href: '#',
                    class: 'chat-ellipsis',
                    html: '&#8230;', // HTML entity for ellipsis
                    'data-chat-id': chat.id,
                    'data-chat-title': chat.title
                });

                chatItem.append(chatLink, ellipsisLink);

                // Create docList outside of chatItem
                let docList = null;

                /*
                console.log("getting the chat.document")
                console.log(chat.document)
                console.log("done with chat.document")
                */
                const docEntries = (chat.document)
                    ? Object.entries(chat.document).filter(([key, title]) => {
                        return title && title !== 'null'; 
                        // i.e. keep only if the title is non-empty and not literally the string "null"
                    })
                    : [];

                // Now docEntries is an array of [ [docKey, docTitle], ... ] for valid docs only
                window.chatDocumentsByChatId = window.chatDocumentsByChatId || {};
                window.chatDocumentsByChatId[chat.id] = {};

                if (deployment != 'azure-dall-e-3' && docEntries.length > 0) {
                    const totalDocs = docEntries.length;
                    const docMapForChat = {};

                    const documentHeadingSpan = $('<span>', {
                        class: 'document-heading',
                        text: `Documents (${totalDocs})`,
                        css: { whiteSpace: 'nowrap' }
                    }).attr('title', `${totalDocs} document${totalDocs === 1 ? '' : 's'}`);

                    const headingRow = $('<div>', {
                        class: 'heading-row',
                        css: { display: 'flex', alignItems: 'center', flexWrap: 'nowrap' }
                    }).append(
                        $('<label>', {
                            class: 'paperclip-icon',
                            for: 'uploadTrigger',
                            css: { cursor: 'pointer', marginRight: '10px', marginLeft: '10px' },
                            onclick: 'openUploadModal()'
                        }).append(
                            $('<img>', {
                                src: 'images/paperclip.white.svg',
                                alt: 'Upload Document',
                                title: 'Document types accepted: PDF, Word, PPT, text, markdown, images, etc.',
                                css: { height: '20px', transform: 'rotate(45deg)' }
                            })
                        ),
                        documentHeadingSpan
                    );

                    const docHeadingContainer = $('<div>', {
                        class: 'document-heading-container',
                        css: { display: 'flex', flexDirection: 'column', alignItems: 'flex-start' }
                    }).append(headingRow);

                    docList = $('<ol>', {
                        class: 'document-list',
                        id: `doclist-${chat.id}`,
                        style: `margin-left: 20px; display: ${isCurrentChat ? 'block' : 'none'};`
                    });
                    docList.append(docHeadingContainer);

                    if (chatId == chat.id) {
                        currentChat = chat;
                    }

                    let inlineTokenTotal = 0;
                    let ragTokenTotal = 0;
                    const contextLimit = Number.parseInt(window.context_limit ?? window.contextLimit ?? window.contextLimitTokens ?? 0, 10) || 0;

                    let itemNum = 1;
                    docEntries.forEach(([docKey, docData]) => {
                        const numericDocId = parseInt(docKey, 10);
                        const docTitle = docData.name;
                        const displayTitle = docTitle;
                        const docType = docData.type || '';
                        const isImage = docType.startsWith('image/');
                        const isReady = isImage || docData.ready === true || docData.ready === 1 || docData.ready === '1';
                        const isProcessingTracked = (typeof window.isDocumentProcessingForChat === 'function')
                            ? window.isDocumentProcessingForChat(chat.id, numericDocId)
                            : false;

                        let docSourceRaw = ((docData.source || docData.document_source || '') + '').toLowerCase();
                        const docFullText = docData.full_text_available === true
                            || docData.full_text_available === 1
                            || docData.full_text_available === '1';
                        const docTokensRaw = docData.token_length ?? docData.document_token_length;
                        const docTokenLength = Number.isFinite(docTokensRaw)
                            ? docTokensRaw
                            : parseInt(docTokensRaw, 10);

                        const docEnabled = !(docData.enabled === false
                            || docData.enabled === 0
                            || docData.enabled === '0');

                        if (!docSourceRaw) {
                            if (docFullText) {
                                docSourceRaw = 'inline';
                            } else if (isReady) {
                                docSourceRaw = 'rag';
                            }
                        }

                        let provenanceLabel = '';
                        if (docSourceRaw === 'image') {
                            provenanceLabel = 'Image stored inline (not indexed).';
                        } else if (docSourceRaw === 'inline') {
                            if (docFullText) {
                                provenanceLabel = 'Full document stored inline; no vector search.';
                            } else {
                                provenanceLabel = 'Inline preview stored; remaining text handled via prompt.';
                            }
                        } else if (docSourceRaw === 'rag') {
                            provenanceLabel = isReady
                                ? 'Vector indexed for RAG retrieval.'
                                : 'Queued for RAG indexing…';
                        } else {
                            provenanceLabel = isReady ? 'Document ready.' : 'Processing…';
                        }

                        const tooltipParts = [];
                        if (docType) {
                            tooltipParts.push(docType);
                        }
                        if (provenanceLabel) {
                            tooltipParts.push(provenanceLabel);
                        }
                        /*
                        if (docFullText && docSourceRaw !== 'image') {
                            tooltipParts.push('Full text available in database.');
                        }
                        */
                        if (Number.isFinite(docTokenLength) && docTokenLength > 0) {
                            tooltipParts.push(`≈ ${prettyTokens(docTokenLength)} tokens`);
                        }
                        if (!docEnabled) {
                            tooltipParts.push('Disabled for prompts. Click the icon to re-enable.');
                        }
                        const tooltip = tooltipParts.filter(Boolean).join('\n').trim();

                        if (Number.isFinite(docTokenLength) && docTokenLength > 0) {
                            if (docSourceRaw === 'inline') {
                                inlineTokenTotal += docTokenLength;
                            } else if (docSourceRaw === 'rag') {
                                ragTokenTotal += docTokenLength;
                            }
                        }

                        const docItem = $('<li>', {
                            class: `document-item ${isReady ? 'doc-ready' : 'doc-processing'}`,
                            title: tooltip || docType
                        });
                        if (!docEnabled) {
                            docItem.addClass('document-item--disabled');
                        }
                        docItem.attr({
                            'data-document-id': numericDocId,
                            'data-chat-id': chat.id,
                            'data-document-name': docTitle,
                            'data-document-type': docType,
                            'data-document-source': docSourceRaw || '',
                            'data-document-ready': isReady ? '1' : '0',
                            'data-document-enabled': docEnabled ? '1' : '0',
                            'data-document-tokens': Number.isFinite(docTokenLength) ? docTokenLength : '',
                            'data-base-tooltip': tooltip || docType || ''
                        });

                        const docTitleSpan = $('<span>', {
                            class: 'document-title',
                            html: `${itemNum}. ${displayTitle}${docEnabled ? '' : ' (disabled)'}`,
                            title: tooltip || docType
                        });
                        if (!docEnabled) {
                            docTitleSpan.addClass('document-title--disabled');
                        }
                        docTitleSpan.attr('data-document-id', numericDocId);
                        docTitleSpan.attr('data-chat-id', chat.id);
                        docTitleSpan.attr('data-base-prefix', `${itemNum}. `);
                        docTitleSpan.attr('data-base-name', displayTitle);
                        docTitleSpan.attr('data-base-tooltip', tooltip || docType || '');
                        docTitleSpan.attr('role', 'button');
                        docTitleSpan.attr('tabindex', '0');

                        let statusLabel;
                        const statusTitle = provenanceLabel || (isReady ? 'Ready' : 'Processing…');
                        if (!isReady) {
                            statusLabel = $('<span>', {
                                class: 'document-status status-processing',
                                text: 'Processing…',
                                title: statusTitle
                            });
                        } else if (isProcessingTracked) {
                            statusLabel = $('<span>', {
                                class: 'document-status status-ready',
                                text: docSourceRaw === 'rag' ? 'RAG ready' : 'Ready',
                                title: statusTitle
                            });
                        } else {
                            statusLabel = $('<button>', {
                                type: 'button',
                                class: 'document-status status-ready ready-icon document-status-toggle' + (docEnabled ? '' : ' document-status-toggle--disabled'),
                                role: 'switch',
                                'aria-checked': docEnabled ? 'true' : 'false',
                                'aria-label': docEnabled ? 'Disable this document for future prompts' : 'Currently disabled; click to re-enable',
                                'data-doc-id': numericDocId,
                                'data-chat-id': chat.id,
                                'data-enabled': docEnabled ? 'true' : 'false',
                                title: docEnabled ? 'Disable this document for future prompts' : 'Currently disabled; click to re-enable'
                            }).append(
                                $('<span>', {
                                    class: 'sr-only',
                                    text: docEnabled ? 'Enabled' : 'Disabled'
                                })
                            );
                            statusLabel.attr('data-base-title', statusTitle);
                        }

                        var showTrash = '';
                        if (chat.exchange_type == 'workflow') {
                            showTrash = 'display:none'; // show or hide the trashcan
                        }

                        const deleteBtn = $('<button>', {
                            type: 'button',
                            class: 'delete-document-button',
                            style: showTrash,
                            'data-doc-key': docKey,
                            'data-chat-id': chat.id,
                            'data-cancel': (!isReady && !isImage),
                            title: isReady ? 'Delete this document' : 'Cancel processing',
                            html: isReady ? TRASH_SVG : 'Cancel'
                        });

                        docItem.append(docTitleSpan, ' ', statusLabel, ' ', deleteBtn);
                        docList.append(docItem);

                        docTitleSpan.on('click keypress', function(event) {
                            if (event.type === 'keypress' && event.key !== 'Enter' && event.key !== ' ') {
                                return;
                            }
                            event.preventDefault();
                            var $trigger = $(this);
                            var docId = numericDocId;
                            var chatIdForDoc = chat.id;

                            var docMeta = docMapForChat[docId] || null;
                            if (!docMeta && window.chatDocumentsByChatId && window.chatDocumentsByChatId[chatIdForDoc]) {
                                docMeta = window.chatDocumentsByChatId[chatIdForDoc][docId] || null;
                            }

                            var docTypeLower = ((docMeta && docMeta.document_type) || docType || '').toLowerCase();
                            var docSourceLower = ((docMeta && docMeta.document_source) || docSourceRaw || '').toLowerCase();
                            var isImageDoc = docTypeLower.indexOf('image/') === 0 || docSourceLower === 'image';

                            if (isImageDoc) {
                                var imagePayload = docMeta ? Object.assign({}, docMeta) : {
                                    document_id: docId,
                                    document_name: docTitle,
                                    document_type: docType,
                                    document_source: docSourceRaw || 'image'
                                };
                                var imageSrc = imagePayload.document_text || imagePayload.document_content || imagePayload.src || '';

                                if (imageSrc) {
                                    if (typeof openImagePreview === 'function') {
                                        openImagePreview(Object.assign({}, imagePayload, {
                                            src: imageSrc,
                                            document_content: imageSrc,
                                            document_text: imageSrc
                                        }), $trigger);
                                    } else if (typeof window.openImagePreview === 'function') {
                                        window.openImagePreview(Object.assign({}, imagePayload, {
                                            src: imageSrc,
                                            document_content: imageSrc,
                                            document_text: imageSrc
                                        }), $trigger);
                                    } else {
                                        alert('Image preview is not available at this time.');
                                    }
                                    return;
                                }

                                fetchImagePreview($trigger, docId, chatIdForDoc, imagePayload);
                                return;
                            }

                            fetchDocumentExcerpt($trigger);
                        });

                        const docRecord = {
                            document_id: numericDocId,
                            document_name: docTitle,
                            document_type: docType,
                            document_source: docSourceRaw,
                            source: docSourceRaw,
                            document_ready: isReady ? 1 : 0,
                            document_token_length: Number.isFinite(docTokenLength) ? docTokenLength : 0,
                            document_deleted: 0,
                            enabled: docEnabled,
                            was_enabled: docEnabled,
                            document_content: docData.document_content || null
                        };
                        if (isImage && docData.document_content) {
                            docRecord.document_text = docData.document_content;
                        }
                        docMapForChat[numericDocId] = docRecord;

                        itemNum += 1;
                    });

                    window.chatDocumentsByChatId[chat.id] = docMapForChat;

                    if (totalDocs > 0) {
                        const inlineLabel = inlineTokenTotal > 0 ? `${prettyTokens(inlineTokenTotal)} inline` : null;
                        const ragLabel = (ragTokenTotal > 0 && inlineTokenTotal === 0) ? `${prettyTokens(ragTokenTotal)} via RAG` : null;
                        const summaryParts = [`${totalDocs} document${totalDocs === 1 ? '' : 's'}`];
                        if (inlineLabel) summaryParts.push(inlineLabel);
                        if (ragLabel) summaryParts.push(ragLabel);

                        const summaryText = summaryParts.join(' · ');
                        if (documentHeadingSpan) {
                            //documentHeadingSpan.text(`Documents (${summaryText})`);
                            documentHeadingSpan.text(`Documents`);
                            documentHeadingSpan.attr('title', summaryText);
                        }

                        docHeadingContainer.find('.token-warning').remove();
                        if (inlineTokenTotal > 0 && contextLimit > 0 && inlineTokenTotal > contextLimit) {
                            const warningText = `Inline total ${prettyTokens(inlineTokenTotal)} exceeds context limit of ${prettyTokens(contextLimit)}.`;
                            const warning = $('<span>', {
                                class: 'token-warning',
                                text: warningText,
                                title: 'Document tokens exceed selected model context.'
                            });
                            warning.css({ color: 'yellow', marginTop: '4px', marginLeft: '10px' });
                            docHeadingContainer.append(warning);
                        }
                    }

                }

                // Add hover events
                chatItem.on('mouseenter', function() {
                    $(this).addClass('current-chat');
                }).on('mouseleave', function() {
                    // Only remove 'current-chat' if it's not the actual current chat
                    if (!$(this).hasClass('initial-current-chat')) {
                        $(this).removeClass('current-chat');
                    }
                });

                // On click, expand documents if present
                chatItem.on('click', function(e) {
                    // Avoid interfering with the anchor link or ellipsis link
                    if ($(e.target).is('a') || $(e.target).is('button')) {
                        return;
                    }
                    // Hide all doc lists
                    $('.document-list').hide();
                    // remove current-chat from all chat items
                    $('.chat-item').removeClass('current-chat');

                    // show docList for this item only
                    if (docList) {
                        docList.show();
                    }

                    // mark this as current
                    $(this).addClass('current-chat');
                });

                ellipsisLink.on('click', function(e) {
                    e.preventDefault();
                    showPopup(e, chat.id, chat.title);
                });

                // If it's the active chat, show the docList
                if (isCurrentChat) {
                    printChatTitle = chat.title;
                    document.getElementById('print-title').innerHTML = printChatTitle;
                }

                // Append the chatItem div to the container
                $('.chat-titles-container').append(chatItem);

                // Then append the docList (if any) below it.
                if (docList) {
                    $('.chat-titles-container').append(docList);
                }
            });

            if (typeof window.syncProcessingStateFromServer === 'function') {
                window.syncProcessingStateFromServer(chatData);
            }

            // Update global documentsLength from currentChat's documents (if any)
            if (currentChat && currentChat.document) {
                documentsLength = Object.keys(currentChat.document).length;
            } else {
                documentsLength = 0;
            }
            // Optionally update the upload modal text (if open)
            //updateUploadModalText();

            // Re-bind delete-document button event
            $('.chat-titles-container').off('click', '.delete-document-button');
            $('.chat-titles-container').on('click', '.delete-document-button', function(e) {
                e.preventDefault();
                const docKey = $(this).data('doc-key');
                const chatId = $(this).data('chat-id');
                const isCancel = $(this).data('cancel') === true || $(this).data('cancel') === 'true' || $(this).data('cancel') === 1;
                console.log(`Documents Length 4: ${documentsLength}`);

                if (isCancel) {
                    if (!confirm('Cancel processing for this document?')) {
                        return;
                    }
                    const $button = $(this);
                    $button.prop('disabled', true);
                    cancelProcessingDocuments([parseInt(docKey, 10)], chatId)
                        .catch(function(err) {
                            console.error('Error cancelling document:', err);
                            alert('Unable to cancel this upload. Please try again.');
                        })
                        .finally(function() {
                            $button.prop('disabled', false);
                        });
                    return;
                }

                if (confirm('Are you sure you want to delete this document?')) {
                    $.ajax({
                        url: 'delete-document.php',
                        type: 'POST',
                        data: { chatId: chatId, docKey: docKey },
                        success: function(response) {
                            fetchAndUpdateChatTitles($('#search-input').val(), false);
                        },
                        error: function(xhr, status, error) {
                            console.error('Error deleting document:', error);
                        }
                    });
                }
            });

            $('.chat-titles-container').off('click', '.document-status-toggle');
            $('.chat-titles-container').on('click', '.document-status-toggle', function (event) {
                event.preventDefault();
                const button = $(this);
                if (button.prop('disabled')) {
                    return;
                }
                const docId = parseInt(button.data('doc-id'), 10);
                const chatIdAttr = button.data('chat-id');
                if (!docId || !chatIdAttr) {
                    return;
                }
                toggleDocumentEnabled(button, docId, chatIdAttr);
            });

        },
        error: function(xhr, status, error) {
            searchingIndicator.style.display = 'none';
            chatTitlesContainer.style.opacity = '1'; // Restore opacity
            console.error('Error fetching chat titles:', error);
        }

    });
} // create  render the <nav> chat titles list

function revertEditForm(existingChatId) {
    var originalLink = $('<div>').html($("#chat-" + existingChatId).find('[data-chat-title]').data('chat-title')).text();
    $("#edit-input-" + existingChatId).replaceWith(`
        <a class="chat-link chat-title" 
           title="${originalLink}" 
           href="/${application_path}/${existingChatId}" 
           data-chat-id="${existingChatId}" 
           data-chat-title="${originalLink}">
            ${originalLink}
        </a>
    `);
    $("#edit-confirm-" + existingChatId).remove();
    $("#edit-cancel-" + existingChatId).remove();
    
    var $restoredLink = $("#chat-" + existingChatId + " .chat-link");
    $restoredLink.on('mouseenter', function(e) {
        const chatId = $(this).data('chat-id');
        const chatTitle = $(this).data('chat-title');
        showPopup(e, chatId, chatTitle);
    }).on('mouseleave', startHideTimer);
}

function toggleDocumentEnabled(button, documentId, chatId) {
    button.prop('disabled', true).addClass('document-status-toggle--busy');

    fetch('toggle_document.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            document_id: documentId,
            chat_id: chatId
        })
    })
        .then(function (response) {
            if (!response.ok) {
                throw new Error('Unable to toggle document');
            }
            return response.json();
        })
        .then(function (result) {
            var enabled = !!(result && result.enabled);
            var toggleTitle = enabled ? 'Disable this document for future prompts' : 'Currently disabled; click to re-enable';

            button
                .toggleClass('document-status-toggle--disabled', !enabled)
                .attr('data-enabled', enabled ? 'true' : 'false')
                .attr('aria-checked', enabled ? 'true' : 'false')
                .attr('aria-label', toggleTitle)
                .attr('title', toggleTitle);

            var srText = button.find('.sr-only').first();
            if (!srText.length) {
                srText = $('<span>', { class: 'sr-only' }).appendTo(button);
            }
            srText.text(enabled ? 'Enabled' : 'Disabled');

            var docItem = button.closest('.document-item');
            if (docItem && docItem.length) {
                docItem.toggleClass('document-item--disabled', !enabled);
                docItem.attr('data-document-enabled', enabled ? '1' : '0');
                var titleSpan = docItem.find('.document-title').first();
                if (titleSpan && titleSpan.length) {
                    var baseName = titleSpan.data('base-name') || titleSpan.text().replace(/^\d+\.\s*/, '').replace(/\s+\(disabled\)$/i, '');
                    var basePrefix = titleSpan.data('base-prefix') || '';
                    titleSpan.data('base-name', baseName);
                    var enabledLabel = `${basePrefix}${baseName}`;
                    var disabledLabel = `${basePrefix}${baseName} (disabled)`;

                    if (enabled) {
                        titleSpan.removeClass('document-title--disabled');
                        titleSpan.text(enabledLabel);
                    } else {
                        titleSpan.addClass('document-title--disabled');
                        titleSpan.text(disabledLabel);
                    }

                    var baseTooltip = titleSpan.data('base-tooltip') || '';
                    var updatedTooltip = baseTooltip;
                    if (!enabled) {
                        updatedTooltip = baseTooltip ? `${baseTooltip}\nDisabled for prompts. Click the icon to re-enable.` : 'Disabled for prompts. Click the icon to re-enable.';
                    }
                    titleSpan.attr('title', updatedTooltip);
                    docItem.attr('title', updatedTooltip);
                }
            }

            if (window.chatDocumentsByChatId) {
                window.chatDocumentsByChatId[chatId] = window.chatDocumentsByChatId[chatId] || {};
                if (!window.chatDocumentsByChatId[chatId][documentId]) {
                    var fallbackMeta = (typeof window.extractDocumentMetadataFromElement === 'function')
                        ? window.extractDocumentMetadataFromElement(docItem)
                        : null;
                    if (fallbackMeta) {
                        window.chatDocumentsByChatId[chatId][documentId] = fallbackMeta;
                    }
                }
                if (window.chatDocumentsByChatId[chatId][documentId]) {
                    var docEntry = window.chatDocumentsByChatId[chatId][documentId];
                    docEntry.enabled = enabled;
                    docEntry.was_enabled = enabled;
                    if (!docEntry.document_name) {
                        docEntry.document_name = docItem ? docItem.attr('data-document-name') || '' : '';
                    }
                    if (!docEntry.document_type) {
                        docEntry.document_type = docItem ? docItem.attr('data-document-type') || '' : '';
                    }
                    if (!docEntry.document_source) {
                        docEntry.document_source = docItem ? docItem.attr('data-document-source') || '' : '';
                        docEntry.source = docEntry.document_source;
                    }
                }
            } else {
                window.chatDocumentsByChatId = {};
            }

            var searchTerm = $('#search-input').val() || '';
            fetchAndUpdateChatTitles(searchTerm, false);
        })
        .catch(function (err) {
            console.error('toggleDocumentEnabled error', err);
            alert('Unable to update the document state. Please try again.');
        })
        .finally(function () {
            button.prop('disabled', false).removeClass('document-status-toggle--busy');
        });
}

function copyTitleToClipboard(chatId, title) {
    const tempInput = document.createElement("input");
    tempInput.style.position = "absolute";
    tempInput.style.left = "-9999px";
    tempInput.value = title;
    document.body.appendChild(tempInput);
    tempInput.select();
    document.execCommand("copy");
    document.body.removeChild(tempInput);

    // Show a non-intrusive "Copied!" message
    var chatItem = $("#chat-" + chatId);
    var popup = document.createElement('span');
    popup.className = 'copied-popup show';
    popup.textContent = 'Copied!';
    chatItem.append(popup);

    setTimeout(() => {
        popup.remove();
    }, 2000);

    // Close the popup menu
    closePopupMenu(chatItem);
}

function closePopupMenu(chatItem) {
    chatItem.removeClass('hover');
}

function copyToClipboard(button) {
    // Copy code to clipboard function
    var code = button.parentNode.querySelector('pre code').textContent;
    navigator.clipboard.writeText(code).then(() => {
        var popup = document.createElement('span');
        popup.className = 'copied-popup show';
        popup.textContent = 'Copied!';
        button.parentNode.appendChild(popup);

        setTimeout(() => {
            popup.remove();
        }, 2000);
    });
}

function startHideTimer() {
    // Function to handle hiding popup
    hideTimer = setTimeout(() => {
        // Fade out the popup
        popup.css('opacity', '0');
        // Hide the popup after fade-out completes
        setTimeout(() => {
            popup.hide();
        }, 150);
    }, 250);
}
