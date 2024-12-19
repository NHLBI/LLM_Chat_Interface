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
    var aboutWindow = document.querySelector('.aboutChatWindow');
    var aboutCloser = document.querySelector('.closeAbout');
    aboutWindow.classList.add('show');  // Add the 'show' class to make it visible
    aboutCloser.focus();  // Give focus to the close button
}
function closeAboutUs() {
    var aboutWindow = document.querySelector('.aboutChatWindow');
    aboutWindow.classList.remove('show');  // Remove the 'show' class to hide it
    var userMessage = document.getElementById('userMessage'); // Assuming 'userMessage' is the ID of your input
    userMessage.focus();  // Set focus back to the message input
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
