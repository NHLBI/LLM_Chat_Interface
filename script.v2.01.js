var chatContainer;
var printChatTitle;
var searchingIndicator;
var chatTitlesContainer;
var popup;

$(document).ready(function() {

    searchingIndicator = document.getElementById('searching-indicator');
    chatTitlesContainer = document.querySelector('.chat-titles-container');

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
            chatContainer.scrollTop(chatContainer.prop("scrollHeight"));

            // Clear the textarea and localStorage right after form submission
            userMessage.val("");
            localStorage.removeItem('chatDraft_' + chatId);
        }

        if (messageContent !== "") {
            $.ajax({
                type: "POST",
                url: "ajax_handler.php",
                data: {
                    message: messageContent,
                    chat_id: chatId,
                    user: user
                },

                beforeSend: function() {
                    $('.waiting-indicator').show();
                },
                error: function() {
                    $('.waiting-indicator').hide();
                },

                success: function(response) {
                    $('.waiting-indicator').hide();

                    fetchAndUpdateChatTitles(search_term,0);
                    var jsonResponse = JSON.parse(response);
                    var gpt_response = jsonResponse['gpt_response'];

                    // Store the raw response
                    var raw_gpt_response = gpt_response;

                    var deployment = jsonResponse['deployment'];
                    var error = jsonResponse['error'];

                    // Handle errors in the response
                    if (error) {
                        console.log("FOUND AN ERROR IN THE RESPONSE");
                        alert('Error: ' + gpt_response);
                        return;
                    }

                    // Check if gpt_response is null or undefined
                    if (!gpt_response) {
                        gpt_response = "The message could not be processed.";
                    }

                    // Process code blocks in gpt_response
                    gpt_response = formatCodeBlocks(gpt_response);

			        console.log(jsonResponse);
                    const path = "/" + application_path + "/" + jsonResponse.new_chat_id;
                    console.log("this is the application path: " + path);

                    if (jsonResponse.new_chat_id) {
                        window.location.href = path;
                        return;
                    }

                    // Check if the deployment configuration exists
                    if (deployments[deployment]) {
                        var imgSrc = 'images/' + deployments[deployment].image;
                        var imgAlt = deployments[deployment].image_alt;

                        // Create the assistant message element
                        var assistantMessageElement = $('<div class="message assistant-message" style="margin-bottom: 30px;"></div>');

                        // Add the assistant's icon
                        assistantMessageElement.prepend('<img src="' + imgSrc + '" alt="' + imgAlt + '" class="openai-icon">');

                        assistantMessageElement.append('<span>' + gpt_response + '</span>');

                        // Append the assistant message to the chat container
                        chatContainer.append(assistantMessageElement);

                        // Add the copy button
                        addCopyButton(assistantMessageElement, raw_gpt_response);
                    }

                    // Scroll to the bottom of the chat container
                    chatContainer.scrollTop(chatContainer.prop("scrollHeight"));

                    // Re-run Highlight.js on the newly added content
                    hljs.highlightAll();

                }
            });
        }
    });

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
                scrollToBottom();
            }
        });
    }

    function displayMessages(chatMessages) {
        chatMessages.forEach(function(message) {
            var sanitizedPrompt = sanitizeString(message.prompt).replace(/\n/g, '<br>');

            // Format the reply to include code blocks
            var sanitizedReply = formatCodeBlocks(message.reply);

            var userMessageElement = $('<div class="message user-message"></div>').html(sanitizedPrompt);
            userMessageElement.prepend('<img src="images/user.png" class="user-icon">');
            chatContainer.append(userMessageElement);

            // Check if document_name and document_text are not empty
            if (message.document_name && message.document_text) {
                // Create an image element with the base64 data
                var imgElement = $('<img>').attr('src', message.document_text);

                // Optionally, wrap the image in a div with a class for styling
                var imageContainer = $('<div class="message image-message"></div>').append(imgElement);

                // Append the image to the chat container
                chatContainer.append(imageContainer);
            }

            if (deployments[message.deployment]) {
                var imgSrc = 'images/' + deployments[message.deployment].image;
                var imgAlt = deployments[message.deployment].image_alt;

                var assistantMessageElement = $('<div class="message assistant-message"></div>').html(sanitizedReply);

                // Add the assistant's icon
                assistantMessageElement.prepend('<img src="' + imgSrc + '" alt="' + imgAlt + '" class="openai-icon">');

                // Append the assistant message to the chat container
                chatContainer.append(assistantMessageElement);

                // Add the copy button
                addCopyButton(assistantMessageElement, message.reply);
            }

            // Re-run Highlight.js on new content
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




