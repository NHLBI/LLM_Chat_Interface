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
    scrollTimeout = setTimeout(scrollToBottom, 100); // Adjust as needed
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

        var rawMessageContent = userMessage.val().trim();
        var sanitizedMessageContent = replaceNonAsciiCharacters(rawMessageContent);

        // Optionally, show a warning if the message was modified
        if (sanitizedMessageContent !== rawMessageContent) {
            if (!confirm("Your message contains some special characters that might cause issues. Click OK to send the modified message or Cancel to edit your message.")) {
                return;
            }
        }

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
                document.getElementById('modelSelectButton').style.display = 'block';
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

			        console.log(jsonResponse);
                    const path = "/" + application_path + "/" + jsonResponse.new_chat_id;
                    console.log("this is the application path: " + path);

                    if (jsonResponse.new_chat_id) {
                        window.location.href = path;
                        return;
                    }

                    var assistantMessageElement = $('<div class="message assistant-message" style="margin-bottom: 30px;"></div>');

                    // Prepend the assistant's icon
                    var imgSrc = 'images/' + deployments[deployment].image;
                    var imgAlt = deployments[deployment].image_alt;
                    assistantMessageElement.prepend('<img src="' + imgSrc + '" alt="' + imgAlt + '" class="openai-icon">');

                    // If an image was generated
                    if (image_gen_name) {
                        var imgElement = $('<img>')
                            .attr('class', 'image-message')
                            .attr('src', '../image_gen/small/' + image_gen_name)
                            .attr('alt', 'Generated Image')
                            .on('load', function () {
                                // Scroll to the bottom only after the image is loaded
                                debounceScroll();
                            });

                        assistantMessageElement.append(imgElement);

                        // Add the download button for the full-size image
                        addDownloadButton(assistantMessageElement, '../image_gen/fullsize/' + image_gen_name);

                    // Otherwise, display text
                    } else if (gpt_response) {
                        // Display the text response
                        gpt_response = formatCodeBlocks(gpt_response);
                        assistantMessageElement.append('<span>' + gpt_response + '</span>');
                        addCopyButton(assistantMessageElement, gpt_response); // Add copy button for text
                    }

                    // Append the message to the chat container
                    chatContainer.append(assistantMessageElement);

                    // Update chat titles
                    fetchAndUpdateChatTitles(search_term,0);

                    // Syntax highlighting + MathJax
                    if (!image_gen_name) {
                        // First highlight code
                        hljs.highlightAll();

                        // Then typeset the math in the newly added message
                        if (window.MathJax) {
                            MathJax.typesetPromise([assistantMessageElement[0]]).then(function () {
                                debounceScroll();
                            }).catch(function(err) {
                                console.error("MathJax typeset failed: " + err.message);
                                debounceScroll();
                            });
                        } else {
                            debounceScroll();
                        }
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

            if (message.exchange_type == 'workflow') {
                icon = 'gear_icon.png';

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

                // Show specific elements (if needed for 'chat' type)
                document.getElementById('messageForm').style.display = 'block';
                document.getElementById('modelSelectButton').style.display = 'block';
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



            var sanitizedPrompt = formatCodeBlocks(message.prompt);
            var userMessageElement = $('<div class="message user-message"></div>').html(sanitizedPrompt);
            userMessageElement.prepend('<img src="images/'+icon+'" class="user-icon" alt="User icon">');
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

        // 2) Hacky filter to replace `\left[ ... \right]` with `\left. ... \right|`
        //    so that MathJax doesn’t choke on sub/superscripts after square brackets:
        
        reply = reply.replace(/\\left\s*\[/g, '\\left.');
        reply = reply.replace(/\\right\s*\]/g, '\\right|');
        reply = reply.replace(/E\[(.*?)\]/g, 'E($1)');

        // Ensure LaTeX is preserved before other processing
        reply = reply.replace(/\\\[([^\]]+)\\\]/g, function(match, latex) {
            return `<div class="math-block">$$ ${latex} $$</div>`;
        });

        reply = reply.replace(/\\\(([^)]+)\\\)/g, function(match, latex) {
            return `<span class="math-inline">$ ${latex} $</span>`;
        });

        // Rest of your existing formatCodeBlocks logic
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

        // Convert to HTML, but carefully
        reply = marked.parse(reply, {
            // Prevent escaped characters from being converted
            sanitize: false,
            // Preserve HTML-like syntax
            breaks: false
        });

        // Replace placeholders with actual code block HTML
        codeBlocks.forEach((block, index) => {
            reply = reply.replace(`<strong>CODE_BLOCK_${index}</strong>`, block);
        });

        return reply;
    }

});
