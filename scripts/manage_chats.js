// chat.js
function startNewChat() {
    $.ajax({
        type: "POST",
        url: "new_chat.php",
        dataType: 'json',
        success: function(response) {

            // The response should contain the new chat's ID
            var newChatId = response.chat_id;
            // Navigate to the new chat page
            //window.location.href = "?chat_id=" + newChatId;
            window.location.href = "/"+application_path+"/" + newChatId;
        }
    });
}
function deleteChat(chatId, chatTitle) {
    if(confirm(`Delete "${chatTitle}"?\nAre you sure you want to delete this chat?`)) {
        // Send an AJAX request to a PHP script to delete the chat
        $.ajax({
            type: "POST",
            url: "delete_chat.php",  // PHP script to delete the chat
            data: {
                chat_id: chatId
            },
            success: function() {

                // Extract the base URL and current chat ID from the current URL
                var baseUrl = window.location.origin + window.location.pathname;
                var currentChatId = baseUrl.split('/').pop();

                // Determine the appropriate redirect
                if (chatId === currentChatId || currentChatId === application_path || currentChatId === 'index.php') {
                    // If deleting the current chat or there's no specific chat ID,
                    // redirect to the base chat page
                    window.location.href = baseUrl.replace(/\/[^\/]*$/, '') + "/";
                } else {
                    // Otherwise, redirect back to the same chat
                    window.location.href = baseUrl;
                }
            }
        });
    }
}
function editChat(chatId, showPopup) {
    // Close any existing edit forms when popup is triggered
    $('.chat-item').each(function() {
        const $existingEditInput = $(this).find('.edit-field');
        if ($existingEditInput.length > 0) {
            const existingChatId = $existingEditInput.attr('id').replace('edit-input-', '');
            revertEditForm(existingChatId);
        }
    });

    var chatItem = $("#chat-" + chatId);
    var chatLink = chatItem.find(".chat-link");
    var originalChatLinkHTML = chatLink.prop('outerHTML');

    const checkicon = `<svg width="20px" height="20px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path fill-rule="evenodd" clip-rule="evenodd" d="M9.9647 14.9617L17.4693 7.44735L18.5307 8.50732L9.96538 17.0837L5.46967 12.588L6.53033 11.5273L9.9647 14.9617Z" fill="#FFFFFF"/></svg>`;
    const cancelicon = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 50 50" width="20px" height="20px"><path d="M 25 2 C 12.309534 2 2 12.309534 2 25 C 2 37.690466 12.309534 48 25 48 C 37.690466 48 48 37.690466 48 25 C 48 12.309534 37.690466 2 25 2 z M 25 4 C 36.609534 4 46 13.390466 46 25 C 46 36.609534 36.609534 46 25 46 C 13.390466 46 4 36.609534 4 25 C 4 13.390466 13.390466 4 25 4 z M 32.990234 15.986328 A 1.0001 1.0001 0 0 0 32.292969 16.292969 L 25 23.585938 L 17.707031 16.292969 A 1.0001 1.0001 0 0 0 16.990234 15.990234 A 1.0001 1.0001 0 0 0 16.292969 17.707031 L 23.585938 25 L 16.292969 32.292969 A 1.0001 1.0001 0 1 0 17.707031 33.707031 L 25 26.414062 L 32.292969 33.707031 A 1.0001 1.0001 0 1 0 33.707031 32.292969 L 26.414062 25 L 33.707031 17.707031 A 1.0001 1.0001 0 0 0 32.990234 15.986328 z" fill="#FFFFFF"/></svg>`;
    
    chatLink.replaceWith(`
        <input class="edit-field" id="edit-input-${chatId}" type="text" aria-label="Chat title edit link" value="${chatLink.text()}">
        <span class="edit-confirm-icon" id="edit-confirm-${chatId}">${checkicon}</span>
        <span class="edit-cancel-icon" id="edit-cancel-${chatId}" style="width: 26px; height: 24px; margin-left: 5px;">${cancelicon}</span>
    `);
    
    var $editInput = $("#edit-input-" + chatId);
    $editInput.focus();

    $editInput.on('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            submitEdit(chatId);
        } else if (e.key === 'Escape') {
            e.preventDefault();
            cancelEdit();
        }
    });

    $("#edit-confirm-" + chatId).click(function() {
        submitEdit(chatId);
    });

    $("#edit-cancel-" + chatId + ", #edit-cancel-icon").click(function() {
        cancelEdit();
    });

    function cancelEdit() {
        revertEditForm(chatId);
    }

}
function submitEdit(chatId) {
    // Get the input field and its value
    var inputField = $("#edit-input-" + chatId);
    var newTitle = inputField.val().trim();

    // Validate the title (optional, but recommended)
    if (!newTitle) {
        alert('Chat title cannot be empty');
        inputField.focus();
        return;
    }

    // Send an AJAX request to update the chat title
    $.ajax({
        type: "POST",
        url: "edit_chat.php",
        data: {
            chat_id: chatId,
            title: newTitle
        },
        success: function(response) {
            // Find the chat link and update its text and attributes
            var chatLink = $("#chat-" + chatId + " .chat-link");
            chatLink.text(newTitle);
            chatLink.attr('title', newTitle);
            chatLink.data('chat-title', newTitle);

            // Replace the input and icons with the original link
            var originalLink = $('<a>', {
                class: 'chat-link chat-title',
                title: newTitle,
                href: `/${application_path}/${chatId}`, // Use the application_path variable here
                text: newTitle,
                'data-chat-id': chatId,
                'data-chat-title': newTitle
            });

            // Remove the input and icons
            inputField.replaceWith(originalLink);
            $(".edit-confirm-icon").remove();
            $(".edit-cancel-icon").remove();

            // Restore hover events
            originalLink.on('mouseenter', function(e) {
                showPopup(e, chatId, newTitle);
            }).on('mouseleave');

            // Optional: Update the page title if this is the current chat
            if (window.location.pathname.includes(`/${application_path}/${chatId}`)) {
                document.title = newTitle;
            }
        },
        error: function(xhr, status, error) {
            console.error('Error updating chat title:', error);
            alert('Failed to update chat title. Please try again.');
        }
    });
}
