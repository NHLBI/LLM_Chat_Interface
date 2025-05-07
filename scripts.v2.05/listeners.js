// Run on window resize
window.addEventListener('resize', adjustChatTitlesHeight);

document.addEventListener('DOMContentLoaded', (event) => {

    const openSearchButton = document.getElementById('open-search');
    const cancelSearchButton = document.getElementById('cancel-search');
    const searchInput = document.getElementById('search-input');

    // Handle click on the search icon button
    openSearchButton.addEventListener('click', function() {
        const searchString = searchInput.value.trim();
        if (searchString == '') {
            alert("The search is empty");
            return;
        }
        //searchInput.classList.add('open');
        cancelSearchButton.style.display = 'inline-block';
        openSearchButton.style.display = 'none';
        //searchInput.focus();
        fetchAndUpdateChatTitles(searchString,0);
    });

    // Handle click on the cancel button
    cancelSearchButton.addEventListener('click', function() {
        searchInput.classList.remove('open');
        cancelSearchButton.style.display = 'none';
        openSearchButton.style.display = 'inline-block';
        searchInput.value = '';
        search_term = '';
        fetchAndUpdateChatTitles('',1);
    });

    // Handle Enter key to trigger immediate search
    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const searchString = searchInput.value.trim();
            if (searchString == '') {
                cancelSearchButton.style.display = 'none';
                openSearchButton.style.display = 'inline-block';

            } else {
                cancelSearchButton.style.display = 'inline-block';
                openSearchButton.style.display = 'none';

            }
            fetchAndUpdateChatTitles(searchString,0);
        }
    });

    // Load saved chat draft
    var savedMessage = localStorage.getItem('chatDraft_' + chatId);
    if (savedMessage) {
        document.getElementById('userMessage').value = savedMessage;
        //console.log("Loaded saved message for chat ID " + chatId + ": ", savedMessage);
    } else {
        document.getElementById('userMessage').value = "";
        //console.log("No saved message found for chat ID " + chatId);
    }

});

// Modify the event listener for the userMessage input
document.getElementById('userMessage').addEventListener('input', (event) => {
    //console.log("Input event for chat ID " + chatId);
    localStorage.setItem('chatDraft_' + chatId, event.target.value);
    //console.log("Saved draft message for chat ID " + chatId + ": ", event.target.value);
});


