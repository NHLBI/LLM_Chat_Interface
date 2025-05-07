function fetchUserImages(chatId, userMessageElement) {
    $.ajax({
        url: 'get_uploaded_images.php',
        type: 'GET',
        data: { chat_id: chatId },
        success: function(data) {
            var chatDocuments = JSON.parse(data);

            // Now each 'entry' is { document_id, document_name, document_content, document_type }
            chatDocuments.forEach(function(doc) {
                // Only process if the file is an image
                if (
                    doc.document_name &&
                    doc.document_content &&
                    /^image\//.test(doc.document_type)
                ) {
                    var docImg = $('<img>')
                        .attr('class', 'image-message')
                        .attr('src', doc.document_content)
                        .attr('alt', doc.document_name || '');

                    // Append to the user message div
                    userMessageElement.append(docImg);
                }
            });

            // Highlight code, run MathJax, scroll, etc.
            hljs.highlightAll();
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
        },
        error: function(xhr, status, error) {
            console.error("Error fetching images: ", error);
        }
    });
}

