var chatContainer;
var printChatTitle;
var searchingIndicator;
var chatTitlesContainer;
var popup;

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
    scrollTimeout = setTimeout(scrollToBottom, 100); // Adjust the delay as needed
}

function scrollToBottom() {
    chatContainer.scrollTop(chatContainer.prop("scrollHeight"));
}


$(document).ready(function() {

    searchingIndicator = document.getElementById('searching-indicator');
    chatTitlesContainer = document.querySelector('.chat-titles-container');
    updatePlaceholder(); // update the placeholder text in the message window

    // Run on page load
    adjustChatTitlesHeight();

    // Display all the chat titles
    fetchAndUpdateChatTitles(search_term,0);

    chatContainer = $(".chat-container");
    var userMessage = $("#userMessage");

    // Set focus on the message input
    userMessage.focus();

    //console.log(chatId);

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
        //console.log("Form submission for chat ID " + chatId);

        var rawMessageContent = userMessage.val().trim();
        var sanitizedMessageContent = replaceNonAsciiCharacters(rawMessageContent);

        // Optionally, show a warning if the message was modified
        if (sanitizedMessageContent !== rawMessageContent) {
            if (!confirm("Your message contains some special characters that might cause issues. Click OK to send the modified message or Cancel to edit your message.")) {
                return;
            }
        }

        var messageContent = base64EncodeUnicode(sanitizedMessageContent); // Encode in Base64 UTF-8

        // Display the user message (prompt) immediately after submission
        if (sanitizedMessageContent !== "") {
            var userMessageDecoded = base64DecodeUnicode(messageContent);
            var sanitizedPrompt = sanitizeString(userMessageDecoded).replace(/\n/g, '<br>');

            var userMessageElement = $('<div class="message user-message"></div>').html(sanitizedPrompt);
            userMessageElement.prepend('<img src="images/user.png" class="user-icon" alt="User icon">');
            chatContainer.append(userMessageElement);

            console.log("THIS IS THE DEPLOYMENT --- "+deployment);

            // Display the image only if document_type is an image MIME type
            if (document_text && document_type) { // Ensure both document_text and document_type are present
                // Check if document_type starts with 'image/'
                if (typeof document_type === 'string' && document_type.toLowerCase().startsWith('image/')) {
                    // Create an image element with the appropriate MIME type
                    // Assuming document_text contains a Base64-encoded string without the data URL prefix
                    //var imgSrc = `data:${document_type};base64,${document_text}`;
                    var imgSrc = document_text;


                    var imgElement = $('<img>')
                        .attr('src', imgSrc)
                        .attr('alt', 'Uploaded Image')
                        .on('error', function() {
                            console.error('Failed to load image.');
                        });

                    // Optionally, wrap the image in a div with a class for styling
                    var imageContainer = $('<div class="message image-message"></div>').append(imgElement);

                    // Append the image to the chat container
                    chatContainer.append(imageContainer);
                }
                // If document_type is not an image MIME type, do not display anything
            }

            // Scroll to the bottom of the chat container
            //chatContainer.scrollTop(chatContainer.prop("scrollHeight"));
            //scrollToBottom();
            debounceScroll();

            // Clear the textarea and localStorage right after form submission
            userMessage.val("");
            localStorage.removeItem('chatDraft_' + chatId);
        }

        if (messageContent !== "") {
            // **Retrieve the selected deployment (model)**
            var deployment = $('#model_select select[name="model"]').val();

            $.ajax({
                type: "POST",
                url: "ajax_handler.php",
                data: {
                    message: messageContent,
                    chat_id: chatId,
                    user: user,
                    deployment: deployment  // **Include the deployment here**
                },

                beforeSend: function() {
                    $('.waiting-indicator').show();
                },
                error: function() {
                    $('.waiting-indicator').hide();
                },

                success: function(response) {
                    $('.waiting-indicator').hide();
                    var jsonResponse = JSON.parse(response);
                    var eid = jsonResponse['eid']; // The Exchange record ID
                    var gpt_response = jsonResponse['gpt_response'];
                    var image_gen_name = jsonResponse['image_gen_name']; // Filename for the generated image
                    var deployment = jsonResponse['deployment'];
                    var error = jsonResponse['error'];

                    if (error) {
                        alert('Error: ' + gpt_response);
                        return;
                    }

                    var assistantMessageElement = $('<div class="message assistant-message" style="margin-bottom: 30px;"></div>');


                    // Prepend the assistant's icon
                    var imgSrc = 'images/' + deployments[deployment].image;
                    var imgAlt = deployments[deployment].image_alt;
                    assistantMessageElement.prepend('<img src="' + imgSrc + '" alt="' + imgAlt + '" class="openai-icon">');

                    if (image_gen_name) {
                        // Display the generated image
                        var imgSrc = './image_gen/small/' + image_gen_name;
                        var imgElement = $('<img>')
                            .attr('class', 'image-message')
                            .attr('src', imgSrc)
                            .attr('alt', 'Generated Image')
                            .on('load', function () {
                                // Scroll to the bottom only after the image is loaded
                                debounceScroll();
                            });

                        assistantMessageElement.append(imgElement);

                        // Add the download button for the full-size image
                        addDownloadButton(assistantMessageElement, './image_gen/fullsize/' + image_gen_name);
                    } else if (gpt_response) {
                        // Display the text response
                        gpt_response = formatCodeBlocks(gpt_response);
                        assistantMessageElement.append('<span>' + gpt_response + '</span>');
                        addCopyButton(assistantMessageElement, gpt_response); // Add copy button for text
                    }

                    // Append the message to the chat container
                    chatContainer.append(assistantMessageElement);

                    fetchAndUpdateChatTitles(search_term,0);

                    // Highlight syntax if the response includes code
                    if (!image_gen_name) {
                        hljs.highlightAll();
                    }

                    // Scroll to the bottom for non-image responses
                    if (!image_gen_name) {
                        debounceScroll();
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

    // Function to add the copy button
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

        // Append the copy button to the message element
        messageElement.append(copyButton);

        // Set the position of the message element to relative
        messageElement.css('position', 'relative');

        // Copy the raw content to clipboard on click
        copyButton.on('click', function() {
            // Use the rawMessageContent directly
            navigator.clipboard.writeText(rawMessageContent).then(function() {
                // Create a subtle popup message
                var popup = $('<span class="copied-chat-popup show">Copied!</span>');
                
                // Style the popup (adjust positioning as needed)
                popup.css({
                    position: 'absolute',
                    top: copyButton.position().top + 4, // Adjust this value as needed
                    left: copyButton.position().left + 150,
                });

                // Append the popup to the message element
                messageElement.append(popup);

                // Remove the popup after 2 seconds
                setTimeout(function() {
                    popup.remove();
                }, 2000);
            }, function(err) {
                console.error('Could not copy text: ', err);
            });
        });
    }


    function loadMessages() {
        $.ajax({
            url: "get_messages.php",
            data: { chat_id: chatId, user: user },
            dataType: 'json',
            success: function(chatMessages) {
                displayMessages(chatMessages);
                //scrollToBottom();
                debounceScroll();
            }
        });
    }

    function displayMessages(chatMessages) {
        chatMessages.forEach(function (message) {

            //------------------------------------------------
            // 1) USER PROMPT
            //------------------------------------------------
            var sanitizedPrompt = sanitizeString(message.prompt).replace(/\n/g, '<br>');
            var userMessageElement = $('<div class="message user-message"></div>').html(sanitizedPrompt);
            userMessageElement.prepend('<img src="images/user.png" class="user-icon">');
            chatContainer.append(userMessageElement);

            //------------------------------------------------
            // 2) DETERMINE IF WE HAVE AN ASSISTANT REPLY
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
                }
            }

            //------------------------------------------------
            // 3) HANDLE GENERATED IMAGE (IF PRESENT)
            //------------------------------------------------
            if (message.image_gen_name) {
                var imgSrc = './image_gen/small/' + message.image_gen_name;
                var imgElement = $('<img>')
                    .attr('class', 'image-message')
                    .attr('src', imgSrc)
                    .attr('alt', 'Generated Image')
                    .on('load', function () {
                        //scrollToBottom(); // Scroll after the image loads
                        debounceScroll();
                    });

                if (assistantMessageElement) {
                    assistantMessageElement.append(imgElement);
                    addDownloadButton(assistantMessageElement, './image_gen/fullsize/' + message.image_gen_name);
                } else {
                    var imageContainer = $('<div class="message assistant-message"></div>');
                    imageContainer.append(imgElement);
                    addDownloadButton(imageContainer, './image_gen/fullsize/' + message.image_gen_name);
                    chatContainer.append(imageContainer);
                }
            }

            //------------------------------------------------
            // 4) HANDLE DOCUMENTS (USER OR ASSISTANT UPLOADED)
            //------------------------------------------------
            if (message.document_name && message.document_text && /^image\//.test(message.document_type)) {
                var imgElement = $('<img>')
                    .attr('class', 'image-message')
                    .attr('src', message.document_text)
                    .attr('alt', message.document_name || '');

                if (message.document_source === 'assistant' && assistantMessageElement) {
                    assistantMessageElement.append(imgElement);
                    addDownloadButton(assistantMessageElement, message.document_text);
                } else if (message.document_source === 'user') {
                    userMessageElement.append(imgElement);
                }
            }

            //------------------------------------------------
            // 5) FINAL STEP: HIGHLIGHT SYNTAX IF ANY
            //------------------------------------------------
            hljs.highlightAll();
        });
    }

    // Function to identify and format code blocks
    function formatCodeBlocks(reply) {
        // Array to hold code blocks temporarily
        let codeBlocks = [];

        // Extract and replace code blocks with placeholders
        reply = reply.replace(/```(\w*)\n([\s\S]*?)```/g, function(match, lang, code) {
            // If language is specified, use it; otherwise default to plaintext
            var languageClass = lang ? `language-${lang}` : 'plaintext';

            // Escape the code content before inserting it
            const sanitizedCode = sanitizeString(code);

            // Save the code block in an array
            codeBlocks.push(`
                <div class="code-block">
                    <div class="language-label">${lang || 'code'}</div>
                    <button class="copy-button" title="Copy Code" aria-label="Copy code to clipboard" onclick="copyToClipboard(this)">
                        <span style="font-size:12px;">Copy Code</span>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                            <path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"></path>
                        </svg>
                    </button>
                    <pre><code class="${languageClass}">${sanitizedCode}</code></pre>
                </div>`);

            // Return a placeholder to be replaced later
            return `__CODE_BLOCK_${codeBlocks.length - 1}__`;
        });

        // Use marked.parse to handle markdown parsing on the rest of the content
        reply = marked.parse(reply);

        // Replace placeholders with the original code block HTML
        codeBlocks.forEach((block, index) => {
            reply = reply.replace(`<strong>CODE_BLOCK_${index}</strong>`, block);
        });

        return reply;
    }

});




