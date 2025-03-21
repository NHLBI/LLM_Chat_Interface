// ui.js
function scrollToBottom() {
    const messageList = document.getElementById('messageList');
    messageList.scrollTop = messageList.scrollHeight;
}
function showIcons(chatItem) {
    $(chatItem).find('.chat-icon').css('visibility', 'visible');
}
function hideIcons(chatItem) {
    $(chatItem).find('.chat-icon').css('visibility', 'hidden');
}

function showAboutUs() {
  const disclaimer = document.querySelector('.disclaimerWindow');
  disclaimer.classList.add('show');
  const closeBtn = disclaimer.querySelector('.closeDisclaimer');
  if (closeBtn) closeBtn.focus();
}
function closeAboutUs() {
  const disclaimer = document.querySelector('.disclaimerWindow');
  disclaimer.classList.remove('show');
  // Return focus if you wish:
  const userMessage = document.getElementById('userMessage');
  if (userMessage) {
    userMessage.focus();
  }
}
function showAboutModels() {
    const aboutWindow = document.querySelector('.aboutModelsWindow');
    aboutWindow.style.display = 'flex';
    aboutWindow.classList.add('show');

    loadDocumentCounts();    // <<<<< call your existing helper here

    document.querySelector('.closeButton').focus();
}


function old_showAboutModels() {
    var aboutWindow = document.querySelector('.aboutModelsWindow');
    var aboutCloser = document.querySelector('.closeButton'); // Updated selector

    aboutWindow.style.display = 'flex';
    updateModelButtonStates(); // <-- call this each time modal opens

    aboutWindow.classList.add('show');  // Add the 'show' class to make it visible
    aboutCloser.focus();  // Give focus to the close button
}
function closeAboutModels() {
    console.log("Close window clicked");
    var aboutWindow = document.querySelector('.aboutModelsWindow');
    aboutWindow.classList.remove('show');  // Remove the 'show' class
    aboutWindow.style.display = 'none';      // Hide the modal by setting display to 'none'
    var userMessage = document.getElementById('userMessage');
    if (userMessage) {
        userMessage.focus();  // Return focus to the message input if it exists
    }
}

function adjustChatTitlesHeight() {
    // Select the chat-titles-container and the menu bottom content
    const chatTitlesContainer = document.querySelector('.chat-titles-container');
    const menuBottomContent = document.querySelector('.mt-auto.p-2');

    // Calculate available height
    const viewportHeight = window.innerHeight - 130;
    const menuBottomHeight = menuBottomContent.offsetHeight + 60;

    //console.log(viewportHeight)
    //console.log(menuBottomHeight)

    // Adjust for any padding/margin as needed
    const paddingOffset = 0; // Adjust this as necessary

    // Set the height dynamically with a minimum of 200px
    const newHeight = Math.max(viewportHeight - menuBottomHeight - paddingOffset, 360);
    chatTitlesContainer.style.height = `${newHeight}px`;
}
function updatePlaceholder() {
  const userMessageInput = document.getElementById('userMessage');

  // Priority: dall-e > Document > Default
  if (deployment === 'azure-dall-e-3') {
    userMessageInput.placeholder = "Type a detailed description of your image to create... (Note: does not use previous prompts or images.)";
  } else if (document_name) {
    userMessageInput.placeholder = "Type your questions about your uploaded file... (Note: does not use previous prompts or replies.)";
  } else {
    userMessageInput.placeholder = "Type your message...";
  }
}

